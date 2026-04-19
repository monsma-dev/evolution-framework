<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use PDO;

/**
 * GitHubLicenseDistributor — server-side write tool for the GitHub Vault.
 *
 * Uses the GitHub Contents API (PUT) to read/update licenses.json in a private
 * repository. Requires a fine-grained PAT with repo write access stored in
 * SystemSettings (api.github_license_write_pat) or env GITHUB_LICENSE_WRITE_PAT.
 *
 * Format of licenses.json in the vault:
 * {
 *   "EVOL-XXXXXXXX-XXXXXXXX-XXXXXXXX": {
 *     "email":        "customer@example.com",
 *     "tier":         "starter|pro|enterprise",
 *     "status":       "pending|active|revoked",
 *     "machine_id":   null,
 *     "issued_at":    "2026-04-12T18:00:00Z",
 *     "activated_at": null,
 *     "expires":      "2027-04-12"   // or null for perpetual
 *   }
 * }
 */
final class GitHubLicenseDistributor
{
    private const CONTENTS_API  = 'https://api.github.com/repos/%s/contents/%s';
    private const HTTP_TIMEOUT  = 10;
    private const USER_AGENT    = 'EvolutionMerchantEngine/1.0';

    public function __construct(
        private readonly string $writePat,
        private readonly string $repo,
        private readonly string $filePath = 'licenses.json'
    ) {
    }

    public static function fromDb(PDO $db): self
    {
        $pat      = SystemSettingsService::get($db, 'api.github_license_write_pat', '') ?? '';
        $pat      = is_string($pat) ? $pat : '';
        if ($pat === '') {
            $pat = trim((string)(getenv('GITHUB_LICENSE_WRITE_PAT') ?: ''));
        }
        $repo     = SystemSettingsService::get($db, 'merchant.github_vault_repo', '') ?? '';
        $repo     = is_string($repo) ? $repo : '';
        if ($repo === '') {
            $repo = trim((string)(getenv('GITHUB_LICENSE_REPO') ?: ''));
        }
        $filePath = SystemSettingsService::get($db, 'merchant.github_vault_file', 'licenses.json') ?? 'licenses.json';
        return new self($pat, $repo, is_string($filePath) ? $filePath : 'licenses.json');
    }

    public function isConfigured(): bool
    {
        return $this->writePat !== '' && $this->repo !== '';
    }

    /**
     * Issue a new license key. Writes to GitHub + returns the key.
     *
     * @return array{ok: bool, key?: string, error?: string}
     */
    public function issueLicense(
        string $customerEmail,
        string $tier = 'starter',
        ?string $expires = null,
        ?string $stripeSession = null
    ): array {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'GitHub vault not configured (set api.github_license_write_pat + merchant.github_vault_repo)'];
        }

        $key = self::generateKey();

        $vault = $this->readVault();
        if (!$vault['ok']) {
            return $vault;
        }

        $licenses = $vault['data'];
        $licenses[$key] = [
            'email'        => $customerEmail,
            'tier'         => $tier,
            'status'       => 'pending',
            'machine_id'   => null,
            'issued_at'    => gmdate('c'),
            'activated_at' => null,
            'expires'      => $expires,
            'stripe_session' => $stripeSession,
        ];

        $write = $this->writeVault($licenses, $vault['sha'] ?? null, "Issue license {$key} for {$customerEmail}");
        if (!$write['ok']) {
            return $write;
        }

        EvolutionLogger::log('merchant', 'license_issued', ['key' => $key, 'email' => $customerEmail, 'tier' => $tier]);

        return ['ok' => true, 'key' => $key];
    }

    /**
     * Auto-Lock: bind a machine_id to a pending key on first activation.
     * Returns ok=false with reason if already bound to a different machine.
     *
     * @return array{ok: bool, key?: string, status?: string, error?: string}
     */
    public function activateLicense(string $licKey, string $machineId): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'GitHub vault not configured'];
        }

        $vault = $this->readVault();
        if (!$vault['ok']) {
            return $vault;
        }

        $licenses = $vault['data'];

        if (!isset($licenses[$licKey])) {
            return ['ok' => false, 'error' => 'License key not found'];
        }

        $entry = $licenses[$licKey];

        if (($entry['status'] ?? '') === 'revoked') {
            return ['ok' => false, 'error' => 'License has been revoked'];
        }

        $boundMachine = $entry['machine_id'] ?? null;

        if ($boundMachine !== null && $boundMachine !== $machineId) {
            EvolutionLogger::log('merchant', 'activation_rejected_different_machine', ['key' => substr($licKey, 0, 12)]);
            return ['ok' => false, 'error' => 'License is bound to a different machine'];
        }

        if ($boundMachine === $machineId) {
            return ['ok' => true, 'key' => $licKey, 'status' => 'already_active'];
        }

        // First activation — lock the machine
        $licenses[$licKey]['machine_id']   = $machineId;
        $licenses[$licKey]['status']       = 'active';
        $licenses[$licKey]['activated_at'] = gmdate('c');

        $write = $this->writeVault($licenses, $vault['sha'] ?? null, "Activate license {$licKey}");
        if (!$write['ok']) {
            return $write;
        }

        EvolutionLogger::log('merchant', 'license_activated', ['key' => substr($licKey, 0, 12), 'machine' => substr($machineId, 0, 20)]);

        return ['ok' => true, 'key' => $licKey, 'status' => 'activated'];
    }

    /**
     * Revoke a license (sets status = revoked on GitHub).
     *
     * @return array{ok: bool, error?: string}
     */
    public function revokeLicense(string $licKey, string $reason = 'revoked by admin'): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'GitHub vault not configured'];
        }

        $vault = $this->readVault();
        if (!$vault['ok']) {
            return $vault;
        }

        $licenses = $vault['data'];
        if (!isset($licenses[$licKey])) {
            return ['ok' => false, 'error' => 'License key not found'];
        }

        $licenses[$licKey]['status']    = 'revoked';
        $licenses[$licKey]['notes']     = $reason;

        $write = $this->writeVault($licenses, $vault['sha'] ?? null, "Revoke license {$licKey}");
        EvolutionLogger::log('merchant', 'license_revoked', ['key' => substr($licKey, 0, 12)]);

        return $write;
    }

    /**
     * @return array{ok: bool, data?: array<string, mixed>, sha?: string, error?: string}
     */
    public function readVault(): array
    {
        $url = sprintf(self::CONTENTS_API, rawurlencode($this->repo), rawurlencode($this->filePath));
        $ctx = $this->ctx('GET');
        $raw = @file_get_contents($url, false, $ctx);
        $status = $this->lastStatus();

        if ($raw === false || $status === 0) {
            return ['ok' => false, 'error' => 'GitHub API unreachable'];
        }

        if ($status === 404) {
            return ['ok' => true, 'data' => [], 'sha' => null];
        }

        if ($status !== 200) {
            return ['ok' => false, 'error' => "GitHub API HTTP {$status}"];
        }

        $meta = json_decode($raw, true);
        if (!is_array($meta)) {
            return ['ok' => false, 'error' => 'Invalid GitHub API response'];
        }

        $content = base64_decode(str_replace(["\n", "\r"], '', (string)($meta['content'] ?? '')));
        $data    = json_decode($content, true);

        return [
            'ok'   => true,
            'data' => is_array($data) ? $data : [],
            'sha'  => (string)($meta['sha'] ?? ''),
        ];
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     * @return array{ok: bool, error?: string}
     */
    private function writeVault(array $data, ?string $sha, string $message): array
    {
        $url     = sprintf(self::CONTENTS_API, rawurlencode($this->repo), rawurlencode($this->filePath));
        $payload = [
            'message' => $message,
            'content' => base64_encode(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: ''),
        ];
        if ($sha !== null && $sha !== '') {
            $payload['sha'] = $sha;
        }

        $ctx = $this->ctx('PUT', json_encode($payload));
        $raw = @file_get_contents($url, false, $ctx);
        $status = $this->lastStatus();

        if ($status === 200 || $status === 201) {
            return ['ok' => true];
        }

        return ['ok' => false, 'error' => "GitHub write failed HTTP {$status}: " . mb_substr((string)$raw, 0, 200)];
    }

    private function ctx(string $method, ?string $body = null): mixed
    {
        $headers = [
            'Authorization: token ' . $this->writePat,
            'Accept: application/vnd.github.v3+json',
            'User-Agent: ' . self::USER_AGENT,
            'Content-Type: application/json',
        ];
        $opts = [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'timeout'       => self::HTTP_TIMEOUT,
            'ignore_errors' => true,
        ];
        if ($body !== null) {
            $opts['content'] = $body;
        }
        return stream_context_create(['http' => $opts]);
    }

    private function lastStatus(): int
    {
        foreach ((array)($GLOBALS['http_response_header'] ?? ($http_response_header ?? [])) as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$h, $m)) {
                return (int)$m[1];
            }
        }
        return 0;
    }

    public static function generateKey(): string
    {
        $hex = bin2hex(random_bytes(12));
        return 'EVOL-' . strtoupper(substr($hex, 0, 8)) . '-' . strtoupper(substr($hex, 8, 8)) . '-' . strtoupper(substr($hex, 16, 8));
    }
}
