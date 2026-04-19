<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Sterrenwacht — trend-telescoop (RSS/HTTP, geen LLM), weekly future-tech brief,
 * Elegance Gallery (10/10), Governor's Lounge ansichtkaarten.
 */
final class EvolutionObservatoryService
{
    private const LAST_WEEKLY = 'storage/evolution/observatory_last_weekly.json';
    private const LOUNGE_JSONL = 'storage/evolution/governor_lounge.jsonl';

    /**
     * @return array<string, mixed>|null
     */
    private static function cfg(Config $config): ?array
    {
        $evo = $config->get('evolution', []);
        $o = is_array($evo) ? ($evo['observatory'] ?? []) : null;

        return is_array($o) && filter_var($o['enabled'] ?? true, FILTER_VALIDATE_BOOL) ? $o : null;
    }

    /**
     * Fetch headlines from configured RSS/Atom URLs (credit-free).
     *
     * @return list<array{url: string, titles: list<string>, ok: bool, error?: string}>
     */
    public static function trendTelescopeFetch(Config $config): array
    {
        $c = self::cfg($config);
        if ($c === null) {
            return [];
        }
        $urls = $c['rss_urls'] ?? [
            'https://www.php.net/releases/feed.php',
            'https://github.com/php/php-src/releases.atom',
        ];
        if (!is_array($urls)) {
            $urls = [];
        }
        $timeout = max(3, min(20, (int) ($c['http_timeout_seconds'] ?? 10)));
        $maxTitles = max(3, min(25, (int) ($c['max_headlines_per_feed'] ?? 12)));
        $out = [];
        foreach ($urls as $url) {
            if (!is_string($url) || !str_starts_with($url, 'http')) {
                continue;
            }
            $res = EvolutionJsonHttp::get($url, $timeout);
            if (!($res['ok'] ?? false) || ($res['body'] ?? '') === '') {
                $out[] = ['url' => $url, 'titles' => [], 'ok' => false, 'error' => 'fetch failed'];

                continue;
            }
            $titles = self::extractFeedTitles((string) $res['body'], $maxTitles);
            $out[] = ['url' => $url, 'titles' => $titles, 'ok' => true];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function extractFeedTitles(string $xml, int $max): array
    {
        $titles = [];
        if (preg_match_all('#<title(?:\s[^>]*)?>([^<]+)</title>#i', $xml, $m)) {
            foreach ($m[1] as $t) {
                $t = html_entity_decode(trim((string) $t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($t !== '' && !preg_match('#^(feed|php\.net|releases)$#i', $t)) {
                    $titles[] = $t;
                }
                if (count($titles) >= $max) {
                    break;
                }
            }
        }

        return array_values(array_unique($titles));
    }

    /**
     * Weekly run: trends + composer outdated snapshot + optional Rust hint → wiki + cache file.
     *
     * @return array<string, mixed>
     */
    public static function runWeeklyObservatory(Config $config): array
    {
        $c = self::cfg($config);
        if ($c === null) {
            return ['ok' => false, 'error' => 'observatory disabled'];
        }

        $feeds = self::trendTelescopeFetch($config);
        $pk = EvolutionPackageChecker::composerOutdatedDirect();
        $rust = EvolutionPackageChecker::cargoOutdatedHint((string) ($c['rust_cargo_rel_path'] ?? 'storage/evolution/native_sandbox'));

        $brief = "## Future Tech — " . gmdate('Y-m-d') . "\n\n";
        $brief .= "### Trend telescope (headlines)\n\n";
        foreach ($feeds as $f) {
            $brief .= '- **' . ($f['url'] ?? '?') . "**\n";
            if (!empty($f['titles']) && is_array($f['titles'])) {
                foreach (array_slice($f['titles'], 0, 8) as $t) {
                    $brief .= '  - ' . $t . "\n";
                }
            } elseif (!empty($f['error'])) {
                $brief .= '  - _(unavailable: ' . $f['error'] . ")_\n";
            }
            $brief .= "\n";
        }
        $brief .= "### Composer (direct outdated snapshot)\n\n";
        $brief .= '```text' . "\n" . mb_substr((string) ($pk['stdout'] ?? ''), 0, 2500) . "\n```\n\n";
        $brief .= "### Rust sandbox hint\n\n" . ($rust['note'] ?? '') . "\n\n```text\n" . mb_substr((string) ($rust['stdout'] ?? ''), 0, 1200) . "\n```\n";

        EvolutionWikiService::appendObservatoryTrendBrief($config, $brief);

        $payload = [
            'ts' => gmdate('c'),
            'feeds' => $feeds,
            'composer_ok' => $pk['ok'] ?? false,
            'rust' => $rust,
        ];
        $cache = BASE_PATH . '/' . self::LAST_WEEKLY;
        $dir = dirname($cache);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($cache, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        EvolutionLogger::log('observatory', 'weekly', ['feeds' => count($feeds)]);

        $postcardBody = (string) ($c['weekly_postcard_template'] ?? '');
        if ($postcardBody === '') {
            $postcardBody = "Governor — weekly Sterrenwacht scan complete. Review the Observatory section in the wiki for PHP / ecosystem signals. "
                . "During study hours, we can prep the Junior for upcoming runtime changes.\n\n"
                . 'Zen lab: hierarchy is relaxed; sandbox experiments are for learning, not production.';
        }
        self::writeGovernorPostcard($config, 'Observatory', $postcardBody, 'weekly');

        return ['ok' => true, 'cache' => $cache, 'payload' => $payload];
    }

    /**
     * Master-Sensei: alleen echte 10/10 snippets naar de Elegance Gallery.
     *
     * @param array<string, mixed> $meta
     */
    public static function promoteToEleganceGallery(Config $config, string $title, string $code, float $score, array $meta = []): bool
    {
        $c = self::cfg($config);
        if ($c === null) {
            return false;
        }
        $min = (float) ($c['elegance_gallery_min_score'] ?? 10.0);
        if ($score + 0.0001 < $min) {
            return false;
        }
        EvolutionWikiService::appendEleganceGalleryEntry($config, $title, $code, $score, $meta);
        EvolutionLogger::log('observatory', 'elegance_gallery', ['title' => $title, 'score' => $score]);

        return true;
    }

    /**
     * Ansichtkaart naar de Governor — JSONL + wiki mirror.
     */
    public static function writeGovernorPostcard(Config $config, string $fromRole, string $body, string $kind = 'note'): void
    {
        $c = self::cfg($config);
        if ($c === null) {
            return;
        }

        $row = [
            'ts' => gmdate('c'),
            'from' => mb_substr($fromRole, 0, 80),
            'kind' => mb_substr($kind, 0, 40),
            'body' => mb_substr($body, 0, 8000),
        ];
        $path = BASE_PATH . '/' . self::LOUNGE_JSONL;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = json_encode($row, JSON_UNESCAPED_UNICODE);
        if (is_string($line)) {
            @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
        }

        EvolutionWikiService::appendGovernorLoungePostcard($config, $fromRole, $body, $kind);
        EvolutionLogger::log('observatory', 'governor_postcard', ['from' => $fromRole, 'kind' => $kind]);
    }

    /**
     * Junior: wekelijkse ansichtkaart (korte template + optionele extra).
     */
    public static function juniorWeeklyPostcard(Config $config, string $message): void
    {
        self::writeGovernorPostcard($config, 'Junior Architect', $message, 'weekly_junior');
    }

    /**
     * @return array<string, mixed>
     */
    public static function readLastWeeklyCache(): array
    {
        $p = BASE_PATH . '/' . self::LAST_WEEKLY;
        if (!is_file($p)) {
            return ['hint' => 'Run php ai_bridge.php evolution:observatory weekly'];
        }
        $raw = @file_get_contents($p);
        $j = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($j) ? $j : [];
    }

    public static function isWeeklyDue(Config $config): bool
    {
        $c = self::cfg($config);
        if ($c === null) {
            return false;
        }
        $dow = max(0, min(6, (int) ($c['weekly_run_weekday'] ?? 1)));
        $tzName = (string) ($c['timezone'] ?? 'UTC');
        try {
            $tz = new DateTimeZone($tzName);
        } catch (\Throwable) {
            $tz = new DateTimeZone('UTC');
        }
        $now = new DateTimeImmutable('now', $tz);

        return (int) $now->format('w') === $dow;
    }
}
