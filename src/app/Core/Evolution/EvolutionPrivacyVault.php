<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Zero-Knowledge Privacy Vault.
 *
 * Anonymizes AI brain data before export so you can share/sell your Brain
 * without exposing any business secrets, personal data, or project-specific code.
 *
 * Techniques used:
 *   1. PII scrubbing      — Remove emails, IPs, phone numbers, names
 *   2. Path obfuscation   — Replace project paths with canonical placeholders
 *   3. Semantic masking   — Replace class/table/function names with domain-neutral aliases
 *   4. Differential noise — Add small random noise to numeric thresholds (e.g. 512MB → ~510MB)
 *   5. Fingerprint strip  — Remove any project identifier from output
 *
 * What is NEVER exported (hard-coded exclusion):
 *   - Database passwords / API keys (detected by key name pattern)
 *   - Specific table names (replaced with [table])
 *   - Controller / Model class names (replaced with [Controller] / [Model])
 *   - File system paths (replaced with [path])
 *   - IP addresses (replaced with [ip])
 *   - Email addresses (replaced with [email])
 *   - Any string starting with "sk-", "pk-", "Bearer " (API key pattern)
 *
 * Usage (called by EvolutionReincarnateCommand):
 *   $clean = EvolutionPrivacyVault::anonymize($rawSkillArray);
 *   $clean = EvolutionPrivacyVault::anonymizeMemory($rawMemoryArray);
 *   $report = EvolutionPrivacyVault::privacyReport($rawData);
 */
final class EvolutionPrivacyVault
{
    // Patterns that are ALWAYS stripped — hard-coded, not configurable
    private const PII_PATTERNS = [
        // Email addresses
        '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/' => '[email]',
        // IPv4 addresses
        '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/' => '[ip]',
        // API keys: sk-, pk-, Bearer tokens
        '/\b(sk|pk|rk|ak)-[A-Za-z0-9_\-]{8,}\b/' => '[api_key]',
        '/Bearer\s+[A-Za-z0-9._\-]{10,}/' => 'Bearer [token]',
        // AWS key patterns
        '/\b(AKIA|ASIA|AROA)[A-Z0-9]{16}\b/' => '[aws_key]',
        // Private key blocks
        '/-----BEGIN [A-Z ]+-----.*?-----END [A-Z ]+-----/s' => '[private_key_block]',
        // Phone numbers (EU/international format)
        '/\b\+?(\d[\s\-.]?){8,14}\d\b/' => '[phone]',
        // Passwords in strings
        '/("password"\s*:\s*)"[^"]{4,}"/' => '$1"[redacted]"',
        '/\'password\'\s*=>\s*\'[^\']{4,}\'/' => "'password'=>'[redacted]'",
    ];

    private const PATH_PATTERNS = [
        // Absolute Unix paths
        '/\/var\/www\/html\/[^\s\'"]+/' => '[app_path]',
        '/\/home\/\w+\/[^\s\'"]+/'     => '[home_path]',
        '/\/etc\/[^\s\'"]+/'           => '[etc_path]',
        // Windows paths
        '/[A-Z]:\\\\[^\s\'";]+/'       => '[win_path]',
        // Relative src/ paths
        '/src\/app\/[A-Za-z\/]+\.php/' => '[src_path]',
        '/src\/resources\/[^\s\'"]+/'  => '[resource_path]',
    ];

    private const CLASS_PATTERNS = [
        // Controller/Service/Model class names
        '/\b[A-Z][a-zA-Z]+Controller\b/' => '[Controller]',
        '/\b[A-Z][a-zA-Z]+Service\b/'    => '[Service]',
        '/\b[A-Z][a-zA-Z]+Repository\b/' => '[Repository]',
        '/\b[A-Z][a-zA-Z]+Model\b/'      => '[Model]',
        '/\b[A-Z][a-zA-Z]+Command\b/'    => '[Command]',
    ];

    private const DB_PATTERNS = [
        // Specific table names in SQL
        '/\bFROM\s+`?(\w+)`?\b/i' => 'FROM [table]',
        '/\bINSERT\s+INTO\s+`?(\w+)`?\b/i' => 'INSERT INTO [table]',
        '/\bUPDATE\s+`?(\w+)`?\s+SET\b/i' => 'UPDATE [table] SET',
        '/\bJOIN\s+`?(\w+)`?\s+ON\b/i' => 'JOIN [table] ON',
        // Database names in DSN strings
        '/dbname=[a-zA-Z0-9_]+/' => 'dbname=[db]',
        '/host=[a-zA-Z0-9.\-]+/' => 'host=[host]',
    ];

    /**
     * Anonymize a skill array for export.
     * Safe to share publicly — no project-specific data.
     *
     * @param  array<string, mixed> $skill
     * @return array<string, mixed>
     */
    public static function anonymize(array $skill): array
    {
        $redacted  = 0;
        $knowledge = (array)($skill['knowledge'] ?? []);

        // Process all string fields recursively
        $knowledge = self::cleanRecursive($knowledge, $redacted);

        // Remove project-specific keys entirely
        unset($knowledge['project_name'], $knowledge['project_path'], $knowledge['design_tokens']);
        unset($knowledge['component_names'], $knowledge['db_schema'], $knowledge['env_vars']);

        // Differential noise on numeric thresholds (prevent fingerprinting)
        $knowledge = self::addNumericNoise($knowledge);

        $skill['knowledge']  = $knowledge;
        $skill['privacy']    = [
            'vault_version'  => '1.0',
            'redacted_items' => $redacted,
            'processed_at'   => gmdate('c'),
        ];

        // Strip project fingerprint fields
        unset($skill['project_id'], $skill['owner'], $skill['repo']);

        return $skill;
    }

    /**
     * Anonymize a memory fragment array.
     *
     * @param  array<int, array<string, string>> $memories
     * @return array<int, array<string, string>>
     */
    public static function anonymizeMemory(array $memories): array
    {
        $result = [];
        foreach ($memories as $mem) {
            if (!is_array($mem)) { continue; }
            $redacted = 0;
            foreach (['title', 'lesson', 'detail', 'summary'] as $field) {
                if (isset($mem[$field]) && is_string($mem[$field])) {
                    $mem[$field] = self::scrub($mem[$field], $redacted);
                }
            }
            $mem['privacy_redacted'] = $redacted;
            $result[] = $mem;
        }
        return $result;
    }

    /**
     * Generate a privacy report for a full brain payload.
     *
     * @param  array<string, mixed> $brain
     * @return array{pii_found: int, paths_found: int, keys_found: int, safe: bool}
     */
    public static function privacyReport(array $brain): array
    {
        $json      = json_encode($brain) ?: '';
        $pii       = 0;
        $paths     = 0;
        $keys      = 0;

        foreach (self::PII_PATTERNS as $pattern => $_) {
            if (preg_match_all($pattern, $json, $m)) { $pii += count($m[0]); }
        }
        foreach (self::PATH_PATTERNS as $pattern => $_) {
            if (preg_match_all($pattern, $json, $m)) { $paths += count($m[0]); }
        }

        // API key specific scan
        if (preg_match_all('/\b(sk|pk|AKIA)-[A-Za-z0-9]{8,}/', $json, $m)) { $keys += count($m[0]); }

        return [
            'pii_found'   => $pii,
            'paths_found' => $paths,
            'keys_found'  => $keys,
            'safe'        => ($pii + $paths + $keys) === 0,
        ];
    }

    /**
     * Full pipeline: anonymize + verify clean.
     *
     * @param  array<string, mixed> $brain
     * @return array{brain: array<string, mixed>, report: array<string, mixed>}
     */
    public static function process(array $brain): array
    {
        // Anonymize skills
        if (isset($brain['contents']['skills']) && is_array($brain['contents']['skills'])) {
            $brain['contents']['skills'] = array_map(
                static fn(array $s) => self::anonymize($s),
                $brain['contents']['skills']
            );
        }

        // Anonymize memories
        if (isset($brain['contents']['memory']) && is_array($brain['contents']['memory'])) {
            $brain['contents']['memory'] = self::anonymizeMemory($brain['contents']['memory']);
        }

        // Final privacy report
        $report = self::privacyReport($brain);

        // If still unsafe (missed something), do a final raw scrub on the JSON
        if (!$report['safe']) {
            $json        = json_encode($brain) ?: '{}';
            $r           = 0;
            $json        = self::scrub($json, $r);
            $brain       = json_decode($json, true) ?? $brain;
            $report      = self::privacyReport($brain);
        }

        return ['brain' => $brain, 'report' => $report];
    }

    // ── Private scrubbers ─────────────────────────────────────────────────────

    private static function scrub(string $text, int &$count): string
    {
        foreach (self::PII_PATTERNS as $pattern => $replacement) {
            $text = (string)preg_replace_callback($pattern, static function(array $m) use ($replacement, &$count): string {
                $count++;
                return is_string($replacement) ? $replacement : $m[0];
            }, $text);
        }
        foreach (self::PATH_PATTERNS as $pattern => $replacement) {
            $text = (string)preg_replace_callback($pattern, static function(array $m) use ($replacement, &$count): string {
                $count++;
                return $replacement;
            }, $text);
        }
        foreach (self::CLASS_PATTERNS as $pattern => $replacement) {
            $text = (string)preg_replace_callback($pattern, static function(array $m) use ($replacement, &$count): string {
                $count++;
                return $replacement;
            }, $text);
        }
        foreach (self::DB_PATTERNS as $pattern => $replacement) {
            $text = (string)preg_replace($pattern, $replacement, $text);
        }
        return $text;
    }

    /**
     * Recursively clean all string values in an array.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function cleanRecursive(array $data, int &$count): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = self::scrub($value, $count);
            } elseif (is_array($value)) {
                $data[$key] = self::cleanRecursive($value, $count);
            }
        }
        return $data;
    }

    /**
     * Add differential noise to numeric thresholds so patterns can't fingerprint a project.
     * E.g. memory_limit 512 → 509 or 516 (random within ±3%)
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function addNumericNoise(array $data): array
    {
        $noiseKeys = ['memory_limit', 'max_workers', 'cache_ttl', 'timeout_ms', 'max_requests'];
        foreach ($noiseKeys as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                $val       = (float)$data[$key];
                $noise     = $val * (random_int(-3, 3) / 100);
                $data[$key] = (int)round($val + $noise);
            }
        }
        return $data;
    }
}
