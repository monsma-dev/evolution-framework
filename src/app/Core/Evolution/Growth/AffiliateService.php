<?php

declare(strict_types=1);

namespace App\Core\Evolution\Growth;

/**
 * AffiliateService — Referral code system with reputation rewards.
 *
 * Each affiliate gets a unique code (EVO-REF-XXXXXX).
 * When a new user signs up/activates via a referral link,
 * the referrer earns reputation points.
 *
 * Storage: storage/evolution/growth/affiliates.json
 *          storage/evolution/growth/referrals.jsonl
 */
final class AffiliateService
{
    private const AFFILIATES_FILE = 'storage/evolution/growth/affiliates.json';
    private const REFERRALS_FILE  = 'storage/evolution/growth/referrals.jsonl';
    private const POINTS_PER_VISIT    = 1;
    private const POINTS_PER_SIGNUP   = 25;
    private const POINTS_PER_LICENSE  = 100;

    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 5));
    }

    /** Create a new affiliate. Returns the affiliate record. */
    public function create(string $name, string $email = '', string $channel = 'general'): array
    {
        $code = $this->generateCode();
        $affiliate = [
            'code'       => $code,
            'name'       => $name,
            'email'      => $email,
            'channel'    => $channel,
            'created_at' => date('c'),
            'points'     => 0,
            'visits'     => 0,
            'signups'    => 0,
            'licenses'   => 0,
            'active'     => true,
        ];

        $affiliates = $this->loadAffiliates();
        $affiliates[$code] = $affiliate;
        $this->saveAffiliates($affiliates);

        return $affiliate;
    }

    /** Record a referral event. Type: visit | signup | license */
    public function record(string $code, string $type, array $meta = []): bool
    {
        $affiliates = $this->loadAffiliates();
        if (!isset($affiliates[$code])) {
            return false;
        }

        $points = match ($type) {
            'visit'   => self::POINTS_PER_VISIT,
            'signup'  => self::POINTS_PER_SIGNUP,
            'license' => self::POINTS_PER_LICENSE,
            default   => 0,
        };

        $affiliates[$code]['points'] += $points;
        $affiliates[$code][$type . 's'] = ($affiliates[$code][$type . 's'] ?? 0) + 1;
        $this->saveAffiliates($affiliates);

        $referral = array_merge($meta, [
            'code'        => $code,
            'type'        => $type,
            'points'      => $points,
            'recorded_at' => date('c'),
        ]);
        $file = $this->basePath . '/' . self::REFERRALS_FILE;
        file_put_contents($file, json_encode($referral) . "\n", FILE_APPEND | LOCK_EX);

        return true;
    }

    /** Get affiliate by code. */
    public function get(string $code): ?array
    {
        $affiliates = $this->loadAffiliates();
        return $affiliates[$code] ?? null;
    }

    /** Returns leaderboard sorted by points desc. */
    public function leaderboard(int $limit = 20): array
    {
        $affiliates = array_values($this->loadAffiliates());
        usort($affiliates, static fn($a, $b) => ($b['points'] ?? 0) <=> ($a['points'] ?? 0));
        return array_slice($affiliates, 0, $limit);
    }

    /** Build a referral URL. */
    public function referralUrl(string $code, string $baseUrl = ''): string
    {
        if ($baseUrl === '') {
            $baseUrl = defined('BASE_PATH') ? '' : 'https://evolution-ai.dev';
        }
        return rtrim($baseUrl, '/') . '/evolution?ref=' . urlencode($code);
    }

    /** Returns global stats. */
    public function stats(): array
    {
        $affiliates = $this->loadAffiliates();
        $totalPoints = array_sum(array_column($affiliates, 'points'));
        $totalVisits = array_sum(array_column($affiliates, 'visits'));
        $totalSignups = array_sum(array_column($affiliates, 'signups'));
        $totalLicenses = array_sum(array_column($affiliates, 'licenses'));

        return [
            'total_affiliates' => count($affiliates),
            'total_points'     => $totalPoints,
            'total_visits'     => $totalVisits,
            'total_signups'    => $totalSignups,
            'total_licenses'   => $totalLicenses,
            'conversion_rate'  => $totalVisits > 0
                ? round($totalSignups / $totalVisits * 100, 1)
                : 0.0,
        ];
    }

    private function generateCode(): string
    {
        return 'EVO-REF-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    private function loadAffiliates(): array
    {
        $file = $this->basePath . '/' . self::AFFILIATES_FILE;
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function saveAffiliates(array $affiliates): void
    {
        $file = $this->basePath . '/' . self::AFFILIATES_FILE;
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($file, json_encode($affiliates, JSON_PRETTY_PRINT), LOCK_EX);
    }
}
