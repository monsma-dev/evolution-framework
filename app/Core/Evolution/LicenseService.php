<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * GitHub-based Evolution Core license validator.
 *
 * Flow:
 *   1. Read LICENSE_KEY + MACHINE_ID locally.
 *   2. Fetch licenses.json from a private GitHub repo (GITHUB_LICENSE_PAT).
 *   3. Validate key existence, machine binding, and expiry date.
 *   4. On success: write an HMAC-signed local cache.
 *   5. On GitHub timeout/5xx: fall back to signed cache (max 72 h offline grace).
 *   6. On cache miss/expired/tampered + GitHub unreachable: block Evolution.
 *
 * Config (evolution.json → "license"):
 *   enabled           bool   – master switch (default false)
 *   github_repo       string – "owner/repo"
 *   github_file_path  string – path inside repo, e.g. "licenses.json"
 *   cache_ttl_hours   int    – re-check interval even when cache is valid (default 1)
 *   offline_grace_hours int  – max hours to run from cache without GitHub (default 72)
 *
 * Env / SSM:
 *   LICENSE_KEY        – the license key for this installation
 *   GITHUB_LICENSE_PAT – fine-grained PAT, read-only on the licenses repo
 */
final class LicenseService
{
    private const GITHUB_RAW_API = 'https://api.github.com/repos/%s/contents/%s';
    private const CACHE_FILE     = 'storage/framework/license_cache.json';
    private const HTTP_TIMEOUT   = 8;

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Sovereign Mode — Double-Lock check.
     *
     * Lock 1: EVOLUTION_SOVEREIGN=true (or EVOLUTION_MASTER_BYPASS=true)
     *         must be present in the server environment (Docker .env, not source).
     *
     * Lock 2: If EVOLUTION_SOVEREIGN_MACHINE_HASH is also set, the actual
     *         MachineFingerprintService::generate() value MUST match it exactly.
     *         This prevents a stolen .env from working on a different server.
     *
     * When both locks pass → Police-Agent warning is written to the Evolution log
     * so sovereign usage is always auditable.
     *
     * NEVER hardcode either value in source code — infrastructure-level only.
     */
    public static function isSovereign(): bool
    {
        $envKey = strtolower(trim((string)(getenv('EVOLUTION_SOVEREIGN') ?: '')));
        $bypass = strtolower(trim((string)(getenv('EVOLUTION_MASTER_BYPASS') ?: '')));

        if ($envKey !== 'true' && $envKey !== '1' && $bypass !== 'true' && $bypass !== '1') {
            return false;
        }

        // ── Lock 2: optional machine hash binding ────────────────────────────
        $expectedHash = trim((string)(getenv('EVOLUTION_SOVEREIGN_MACHINE_HASH') ?: ''));
        if ($expectedHash !== '') {
            $actualHash = MachineFingerprintService::generate();
            if (!hash_equals($expectedHash, $actualHash)) {
                // Env var present but machine mismatch — possible stolen .env
                EvolutionLogger::log('sovereign', 'DOUBLE_LOCK_MACHINE_MISMATCH', [
                    'expected_prefix' => substr($expectedHash, 0, 16),
                    'actual_prefix'   => substr($actualHash, 0, 16),
                ]);
                return false;
            }
        }

        // ── Police-Agent audit log ───────────────────────────────────────────
        // Logged max once per process lifetime to avoid flooding logs.
        static $logged = false;
        if (!$logged) {
            $logged = true;
            EvolutionLogger::log('sovereign', 'SOVEREIGN_MODE_ACTIVE', [
                'lock_1' => 'env_var_present',
                'lock_2' => $expectedHash !== '' ? 'machine_hash_verified' : 'no_hash_set',
                'note'   => 'Police-Agent: license check bypassed on this server',
            ]);
        }

        return true;
    }

    /**
     * @return array{ok: bool, source: string, reason: string|null, expires: string|null, machine_id: string}
     */
    public static function check(Config $config): array
    {
        // ── Sovereign Mode ──────────────────────────────────────────────────
        // When EVOLUTION_SOVEREIGN=true (or EVOLUTION_MASTER_BYPASS=true) is
        // set at infrastructure level, skip ALL license validation.
        // This applies ONLY to the owner's server; customers never have this var.
        if (self::isSovereign()) {
            return self::result(true, 'sovereign', null, null);
        }

        $lic = $config->get('evolution.license', []);
        if (!is_array($lic) || !filter_var($lic['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return self::result(true, 'disabled', null, null);
        }

        $licKey   = self::licenseKey($config);
        $machineId = MachineFingerprintService::generate();

        if ($licKey === '') {
            return self::result(
                false,
                'config',
                'Missing license key — set LICENSE_KEY in .env (or on the Docker host when using env_file), '
                . 'or evolution.license.key in src/config/evolution.local.json. '
                . 'Owner server bypass: EVOLUTION_SOVEREIGN=true (see LicenseService).',
                null
            );
        }

        // 1. Try live GitHub validation
        $pat      = trim((string)(getenv('GITHUB_LICENSE_PAT') ?: ''));
        $repo     = trim((string)($lic['github_repo'] ?? ''));
        $filePath = trim((string)($lic['github_file_path'] ?? 'licenses.json'));

        if ($pat !== '' && $repo !== '') {
            $live = self::validateViaGitHub($pat, $repo, $filePath, $licKey, $machineId);
            if ($live !== null) {
                if ($live['ok']) {
                    self::writeCache($live, $machineId, $config);
                } else {
                    self::deleteCache($config);
                }
                return $live;
            }
            // GitHub unreachable — fall through to cache
            EvolutionLogger::log('license', 'github_unreachable', ['repo' => $repo]);
        }

        // 2. Offline grace period
        $graceHours = max(1, (int)($lic['offline_grace_hours'] ?? 72));
        return self::validateFromCache($licKey, $machineId, $graceHours, $config);
    }

    /**
     * @throws \RuntimeException when license is invalid
     */
    public static function assertValid(Config $config): void
    {
        $r = self::check($config);
        if (!$r['ok']) {
            throw new \RuntimeException('Unlicensed Evolution Core: ' . ($r['reason'] ?? 'validation failed'));
        }
    }

    public static function machineId(): string
    {
        return MachineFingerprintService::generate();
    }

    // ─── GitHub Validation ────────────────────────────────────────────────────

    /**
     * Returns null when GitHub is unreachable (network error / 5xx).
     * Returns result array when GitHub responded (even if license is invalid).
     *
     * @return array{ok: bool, source: string, reason: string|null, expires: string|null, machine_id: string}|null
     */
    private static function validateViaGitHub(
        string $pat,
        string $repo,
        string $filePath,
        string $licKey,
        string $machineId
    ): ?array {
        $url  = sprintf(self::GITHUB_RAW_API, rawurlencode($repo), rawurlencode($filePath));
        $ctx  = stream_context_create([
            'http' => [
                'timeout'       => self::HTTP_TIMEOUT,
                'ignore_errors' => true,
                'method'        => 'GET',
                'header'        => implode("\r\n", [
                    'Authorization: token ' . $pat,
                    'Accept: application/vnd.github.v3.raw',
                    'User-Agent: EvolutionLicenseValidator/1.0',
                ]),
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);

        // Network failure or server error → treat as unreachable
        if ($raw === false) {
            return null;
        }

        $status = self::parseHttpStatus($http_response_header ?? []);
        if ($status >= 500 || $status === 0) {
            return null;
        }
        if ($status === 404) {
            return self::result(false, 'github', 'License file not found in repository', null);
        }
        if ($status !== 200) {
            return self::result(false, 'github', "GitHub API error HTTP {$status}", null);
        }

        $licenses = json_decode($raw, true);
        if (!is_array($licenses)) {
            return self::result(false, 'github', 'licenses.json is not valid JSON', null);
        }

        return self::validateEntry($licenses, $licKey, $machineId, 'github');
    }

    // ─── Cache ───────────────────────────────────────────────────────────────

    /**
     * @return array{ok: bool, source: string, reason: string|null, expires: string|null, machine_id: string}
     */
    private static function validateFromCache(
        string $licKey,
        string $machineId,
        int $graceHours,
        Config $config
    ): array {
        $path = self::cachePath($config);
        if (!is_file($path)) {
            return self::result(false, 'cache_miss', 'No valid license cache — connect to GitHub to activate', null);
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return self::result(false, 'cache_error', 'Cannot read license cache file', null);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return self::result(false, 'cache_corrupt', 'License cache is corrupt', null);
        }

        // Tamper check
        $appKey = self::appKey($config);
        if (!self::verifyHmac($data, $appKey)) {
            EvolutionLogger::log('license', 'cache_tampered', []);
            return self::result(false, 'cache_tampered', 'License cache signature mismatch — possible tampering', null);
        }

        // Machine ID binding
        if (($data['machine_id'] ?? '') !== $machineId) {
            return self::result(false, 'machine_mismatch', 'License is bound to a different machine', null);
        }

        // License key match
        if (($data['license_key'] ?? '') !== $licKey) {
            return self::result(false, 'key_mismatch', 'Cached license key does not match current LICENSE_KEY', null);
        }

        // Expiry check
        $expires = (string)($data['expires'] ?? '');
        if ($expires !== '' && $expires < gmdate('Y-m-d')) {
            return self::result(false, 'expired', "License expired on {$expires}", $expires);
        }

        // Age check (offline grace)
        $validatedAt = (string)($data['validated_at'] ?? '');
        $ageSeconds  = 0;
        if ($validatedAt !== '') {
            $ageSeconds = time() - (int)strtotime($validatedAt);
            if ($ageSeconds > $graceHours * 3600) {
                $hoursAgo = round($ageSeconds / 3600, 1);
                return self::result(false, 'grace_expired', "Offline grace period exceeded ({$hoursAgo}h > {$graceHours}h)", $expires ?: null);
            }
        }

        EvolutionLogger::log('license', 'cache_valid', ['hours_old' => round($ageSeconds / 3600, 1)]);
        return self::result(true, 'cache', null, $expires ?: null);
    }

    /**
     * @param array<string, mixed> $licenses  Parsed licenses.json
     * @return array{ok: bool, source: string, reason: string|null, expires: string|null, machine_id: string}
     */
    private static function validateEntry(
        array $licenses,
        string $licKey,
        string $machineId,
        string $source
    ): array {
        if (!array_key_exists($licKey, $licenses)) {
            return self::result(false, $source, 'License key not found', null);
        }

        $entry = $licenses[$licKey];
        if (!is_array($entry)) {
            return self::result(false, $source, 'Malformed license entry', null);
        }

        // Machine binding (empty string = unbound, first-use binds automatically)
        $boundMachine = (string)($entry['machine_id'] ?? '');
        if ($boundMachine !== '' && $boundMachine !== $machineId) {
            return self::result(false, $source, 'License is bound to a different machine', null);
        }

        // Expiry
        $expires = (string)($entry['expires'] ?? '');
        if ($expires !== '' && $expires < gmdate('Y-m-d')) {
            return self::result(false, $source, "License expired on {$expires}", $expires);
        }

        EvolutionLogger::log('license', 'validated', ['source' => $source, 'expires' => $expires]);
        return self::result(true, $source, null, $expires ?: null);
    }

    // ─── Cache I/O ────────────────────────────────────────────────────────────

    /**
     * @param array{ok: bool, source: string, reason: string|null, expires: string|null, machine_id: string} $result
     */
    private static function writeCache(array $result, string $machineId, Config $config): void
    {
        $appKey = self::appKey($config);
        $payload = [
            'license_key'  => self::licenseKey($config),
            'machine_id'   => $machineId,
            'validated_at' => gmdate('c'),
            'expires'      => $result['expires'] ?? '',
        ];
        $payload['hmac'] = self::computeHmac($payload, $appKey);

        $dir = dirname(self::cachePath($config));
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents(self::cachePath($config), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private static function deleteCache(Config $config): void
    {
        $path = self::cachePath($config);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    // ─── HMAC helpers ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     */
    private static function computeHmac(array $data, string $key): string
    {
        $copy = $data;
        unset($copy['hmac']);
        ksort($copy);
        return hash_hmac('sha256', json_encode($copy, JSON_UNESCAPED_UNICODE) ?: '', $key);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function verifyHmac(array $data, string $key): bool
    {
        $stored = (string)($data['hmac'] ?? '');
        if ($stored === '') {
            return false;
        }
        $expected = self::computeHmac($data, $key);
        return hash_equals($expected, $stored);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{ok: bool, source: string, reason: string|null, expires: string|null, machine_id: string}
     */
    private static function result(bool $ok, string $source, ?string $reason, ?string $expires): array
    {
        return [
            'ok'         => $ok,
            'source'     => $source,
            'reason'     => $reason,
            'expires'    => $expires,
            'machine_id' => MachineFingerprintService::generate(),
        ];
    }

    private static function licenseKey(Config $config): string
    {
        $fromEnv = trim((string)(getenv('LICENSE_KEY') ?: ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }
        return trim((string)($config->get('evolution.license.key', '') ?? ''));
    }

    private static function appKey(Config $config): string
    {
        $k = trim((string)(getenv('APP_KEY') ?: ($config->get('app.key', '') ?? '')));
        return $k !== '' ? $k : 'evolution-license-fallback-key';
    }

    private static function cachePath(Config $config): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        return $base . '/' . self::CACHE_FILE;
    }

    /**
     * @param list<string> $headers
     */
    private static function parseHttpStatus(array $headers): int
    {
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$h, $m)) {
                return (int)$m[1];
            }
        }
        return 0;
    }
}
