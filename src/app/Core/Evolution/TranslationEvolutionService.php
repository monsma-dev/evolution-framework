<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;

/**
 * DeepL-backed translation fill for JSON lang files (missing keys vs source locale).
 * Batches requests (up to 50 texts per DeepL call), enforces min interval between HTTP calls,
 * character budget per sync, and backoff on HTTP 429 to reduce runaway cost during Ghost/total-sync.
 */
final class TranslationEvolutionService
{
    private const DEEPL_FREE = 'https://api-free.deepl.com/v2/translate';
    private const DEEPL_PRO = 'https://api.deepl.com/v2/translate';

    private const DEFAULT_MAX_TEXTS_PER_REQUEST = 50;
    private const DEFAULT_MIN_INTERVAL_MS = 250;
    private const DEFAULT_MAX_CHARS_PER_SYNC = 100_000;
    private const DEFAULT_MAX_RETRIES_429 = 6;

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{ok: bool, translated?: string, error?: string}
     */
    public function translateLine(string $text, string $sourceLocale, string $targetLocale, ?string $toneContext = null): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['ok' => true, 'translated' => ''];
        }
        $batch = $this->translateTexts([$text], $sourceLocale, $targetLocale, $toneContext, PHP_INT_MAX);
        if (!($batch['ok'] ?? false)) {
            return ['ok' => false, 'error' => $batch['error'] ?? 'translate failed'];
        }
        $out = $batch['texts'][0] ?? '';

        return ['ok' => true, 'translated' => is_string($out) ? $out : ''];
    }

    /**
     * Merge missing keys into target JSON file using source file + DeepL (batched).
     *
     * @return array{ok: bool, added?: int, error?: string, chars_used?: int, truncated?: bool}
     */
    public function syncJsonFile(string $relativeFile, string $sourceLocale, string $targetLocale): array
    {
        $langRoot = BASE_PATH . '/src/resources/lang';
        $srcPath = $langRoot . '/' . $sourceLocale . '/' . $relativeFile;
        $dstPath = $langRoot . '/' . $targetLocale . '/' . $relativeFile;
        if (!is_file($srcPath)) {
            return ['ok' => false, 'error' => 'source file missing: ' . $srcPath];
        }
        $srcRaw = @file_get_contents($srcPath);
        if (!is_string($srcRaw)) {
            return ['ok' => false, 'error' => 'cannot read source'];
        }
        try {
            $srcData = json_decode($srcRaw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['ok' => false, 'error' => 'source JSON: ' . $e->getMessage()];
        }
        if (!is_array($srcData)) {
            return ['ok' => false, 'error' => 'source not an object'];
        }

        $dstData = [];
        if (is_file($dstPath)) {
            $dstRaw = @file_get_contents($dstPath);
            if (is_string($dstRaw) && $dstRaw !== '') {
                try {
                    $dstData = json_decode($dstRaw, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $dstData = [];
                }
            }
        }
        if (!is_array($dstData)) {
            $dstData = [];
        }

        $tone = $this->buildToneContext($sourceLocale, $targetLocale);
        $flatSrc = self::flatten($srcData);
        $flatDst = self::flatten($dstData);

        /** @var list<string> $missing */
        $missing = [];
        /** @var list<string> $keys */
        $keys = [];
        foreach ($flatSrc as $key => $val) {
            if (!is_string($val)) {
                continue;
            }
            if (isset($flatDst[$key]) && trim((string) $flatDst[$key]) !== '') {
                continue;
            }
            $keys[] = $key;
            $missing[] = $val;
        }

        if ($missing === []) {
            return ['ok' => true, 'added' => 0, 'chars_used' => 0];
        }

        $cfg = $this->getTranslationConfig();
        $maxChars = max(1000, (int) ($cfg['deepl_max_chars_per_sync'] ?? self::DEFAULT_MAX_CHARS_PER_SYNC));
        $maxPerReq = max(1, min(50, (int) ($cfg['deepl_max_texts_per_request'] ?? self::DEFAULT_MAX_TEXTS_PER_REQUEST)));

        $charsPlanned = 0;
        foreach ($missing as $m) {
            $charsPlanned += strlen($m);
        }
        if ($charsPlanned > $maxChars) {
            EvolutionLogger::log('translations', 'deepl_char_cap', [
                'file' => $relativeFile,
                'planned_chars' => $charsPlanned,
                'cap' => $maxChars,
            ]);

            return [
                'ok' => false,
                'error' => 'Would exceed evolution.translations.deepl_max_chars_per_sync (' . $maxChars . '). Shorten source or raise cap in config.',
                'chars_used' => 0,
                'truncated' => true,
            ];
        }

        $added = 0;
        $charsUsed = 0;
        $offset = 0;
        $n = count($missing);
        while ($offset < $n) {
            $slice = array_slice($missing, $offset, $maxPerReq);
            $sliceKeys = array_slice($keys, $offset, count($slice));
            $batch = $this->translateTexts($slice, $sourceLocale, $targetLocale, $tone, $maxChars - $charsUsed);
            if (!($batch['ok'] ?? false)) {
                return ['ok' => false, 'error' => $batch['error'] ?? 'translate failed', 'added' => $added, 'chars_used' => $charsUsed];
            }
            foreach ($sliceKeys as $i => $k) {
                $t = $batch['texts'][$i] ?? '';
                $flatDst[$k] = is_string($t) ? $t : '';
                $added++;
                $charsUsed += strlen($slice[$i] ?? '');
            }
            $offset += count($slice);
        }

        $merged = self::unflatten($flatDst);
        $dir = dirname($dstPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'cannot mkdir target'];
        }
        $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($dstPath, $json . "\n") === false) {
            return ['ok' => false, 'error' => 'cannot write target', 'added' => $added, 'chars_used' => $charsUsed];
        }

        return ['ok' => true, 'added' => $added, 'chars_used' => $charsUsed];
    }

    /**
     * Sync all JSON files under source locale to all target locales (for total-sync). Respects same caps as syncJsonFile per file.
     *
     * @param list<string> $targetLocales
     * @return array{ok: bool, files?: int, total_added?: int, error?: string}
     */
    public function syncAllJsonFiles(string $sourceLocale, array $targetLocales): array
    {
        $root = BASE_PATH . '/src/resources/lang/' . $sourceLocale;
        if (!is_dir($root)) {
            return ['ok' => false, 'error' => 'source locale dir missing: ' . $root];
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        $relFiles = [];
        foreach ($files as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'json') {
                continue;
            }
            $full = $f->getPathname();
            $relFiles[] = ltrim(str_replace('\\', '/', substr($full, strlen($root))), '/');
        }
        sort($relFiles);

        $totalAdded = 0;
        $count = 0;
        foreach ($relFiles as $rel) {
            foreach ($targetLocales as $tgt) {
                if ($tgt === $sourceLocale) {
                    continue;
                }
                $r = $this->syncJsonFile($rel, $sourceLocale, $tgt);
                if (!($r['ok'] ?? false)) {
                    return ['ok' => false, 'error' => $rel . ': ' . ($r['error'] ?? 'failed'), 'files' => $count, 'total_added' => $totalAdded];
                }
                $totalAdded += (int) ($r['added'] ?? 0);
            }
            $count++;
        }

        return ['ok' => true, 'files' => $count, 'total_added' => $totalAdded];
    }

    /**
     * @return array<string, mixed>
     */
    private function getTranslationConfig(): array
    {
        $cfg = $this->container->get('config');
        $t = $cfg->get('evolution.translations', []);

        return is_array($t) ? $t : [];
    }

    /**
     * @return array{ok: bool, texts?: list<string>, error?: string}
     */
    private function translateTexts(array $texts, string $sourceLocale, string $targetLocale, ?string $toneContext, int $charBudgetRemaining): array
    {
        $cfg = $this->getTranslationConfig();
        if (!filter_var($cfg['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['ok' => false, 'error' => 'evolution.translations.enabled is false'];
        }
        $key = trim((string) ($cfg['deepl_api_key'] ?? ''));
        if ($key === '') {
            return ['ok' => false, 'error' => 'DEEPL_API_KEY / evolution.translations.deepl_api_key missing'];
        }

        $src = self::deeplLang($sourceLocale);
        $tgt = self::deeplLang($targetLocale);
        if ($src === null || $tgt === null) {
            return ['ok' => false, 'error' => 'unsupported locale for DeepL'];
        }

        $clean = [];
        foreach ($texts as $t) {
            $clean[] = (string) $t;
        }
        $sum = 0;
        foreach ($clean as $t) {
            $sum += strlen($t);
        }
        if ($sum > $charBudgetRemaining) {
            return ['ok' => false, 'error' => 'internal char budget exceeded'];
        }

        $this->throttle($cfg);

        $url = filter_var($cfg['deepl_pro'] ?? false, FILTER_VALIDATE_BOOL) ? self::DEEPL_PRO : self::DEEPL_FREE;
        $maxRetries = max(0, (int) ($cfg['deepl_max_retries_on_429'] ?? self::DEFAULT_MAX_RETRIES_429));

        $attempt = 0;
        while (true) {
            $result = $this->postTranslate($url, $key, $clean, $src, $tgt, $toneContext);
            $code = $result['status'];
            if ($code === 200 && isset($result['translations'])) {
                EvolutionLogger::log('translations', 'deepl_batch', [
                    'source' => $sourceLocale,
                    'target' => $targetLocale,
                    'count' => count($clean),
                ]);

                return ['ok' => true, 'texts' => $result['translations']];
            }
            if ($code === 429 && $attempt < $maxRetries) {
                $wait = $result['retry_after'] ?? (int) pow(2, $attempt);
                $wait = max(1, min(120, $wait));
                EvolutionLogger::log('translations', 'deepl_429', ['wait_s' => $wait, 'attempt' => $attempt]);
                sleep($wait);
                $attempt++;
                $this->throttle($cfg);

                continue;
            }

            return ['ok' => false, 'error' => $result['error'] ?? ('DeepL HTTP ' . $code)];
        }
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function throttle(array $cfg): void
    {
        $ms = max(0, (int) ($cfg['deepl_min_interval_ms'] ?? self::DEFAULT_MIN_INTERVAL_MS));
        if ($ms <= 0) {
            return;
        }
        static $last = null;
        $now = microtime(true);
        if ($last !== null) {
            $elapsed = ($now - $last) * 1000.0;
            if ($elapsed < $ms) {
                usleep((int) (($ms - $elapsed) * 1000));
            }
        }
        $last = microtime(true);
    }

    /**
     * @param list<string> $texts
     * @return array{status: int, translations?: list<string>, error?: string, retry_after?: int}
     */
    private function postTranslate(string $url, string $authKey, array $texts, string $srcLang, string $tgtLang, ?string $toneContext): array
    {
        $parts = [
            'source_lang' => $srcLang,
            'target_lang' => $tgtLang,
        ];
        if ($toneContext !== null && trim($toneContext) !== '') {
            $parts['context'] = mb_substr(trim($toneContext), 0, 512);
        }
        $body = http_build_query($parts, '', '&', PHP_QUERY_RFC3986);
        foreach ($texts as $t) {
            $body .= '&' . rawurlencode('text') . '=' . rawurlencode($t);
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Authorization: DeepL-Auth-Key ' . $authKey,
                    'Content-Length: ' . (string) strlen($body),
                ]),
                'content' => $body,
                'timeout' => 60,
                'ignore_errors' => true,
            ],
        ]);

        $http_response_header = [];
        $raw = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }

        $retryAfter = null;
        foreach ($http_response_header as $line) {
            if (stripos($line, 'Retry-After:') === 0) {
                $retryAfter = (int) trim(substr($line, strlen('Retry-After:')));
                break;
            }
        }

        if (!is_string($raw)) {
            return ['status' => $code ?: 0, 'error' => 'DeepL HTTP failed (no body)'];
        }

        try {
            $j = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['status' => $code, 'error' => 'DeepL JSON: ' . $e->getMessage()];
        }
        if (!is_array($j)) {
            return ['status' => $code, 'error' => 'DeepL invalid response'];
        }

        if ($code === 200) {
            $tr = $j['translations'] ?? null;
            if (!is_array($tr)) {
                return ['status' => 200, 'error' => 'no translations array'];
            }
            $out = [];
            foreach ($tr as $row) {
                if (is_array($row) && isset($row['text']) && is_string($row['text'])) {
                    $out[] = $row['text'];
                }
            }
            if (count($out) !== count($texts)) {
                return ['status' => 200, 'error' => 'translation count mismatch'];
            }

            return ['status' => 200, 'translations' => $out];
        }

        $msg = (string) ($j['message'] ?? $raw);

        return ['status' => $code, 'error' => $msg, 'retry_after' => $retryAfter];
    }

    /**
     * Short tone hint from an existing translation file (e.g. EN) when available.
     */
    private function buildToneContext(string $sourceLocale, string $targetLocale): string
    {
        $langRoot = BASE_PATH . '/src/resources/lang';
        $hintLocales = ['en', $targetLocale, $sourceLocale];
        $parts = [];
        foreach ($hintLocales as $loc) {
            $p = $langRoot . '/' . $loc . '/common.json';
            if (!is_file($p)) {
                continue;
            }
            $raw = @file_get_contents($p);
            if (!is_string($raw)) {
                continue;
            }
            try {
                $j = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!is_array($j)) {
                continue;
            }
            $flat = self::flatten($j);
            $slice = array_slice($flat, 0, 8, true);
            foreach ($slice as $k => $v) {
                if (is_string($v) && strlen($v) < 120) {
                    $parts[] = $v;
                }
            }
            if ($parts !== []) {
                break;
            }
        }

        return implode(' ', array_slice($parts, 0, 12));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            if (is_array($v)) {
                $out = array_merge($out, self::flatten($v, $key));
            } elseif (is_string($v) || is_numeric($v)) {
                $out[$key] = (string) $v;
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $flat
     * @return array<string, mixed>
     */
    private static function unflatten(array $flat): array
    {
        $out = [];
        foreach ($flat as $path => $value) {
            $segments = explode('.', $path);
            $ref = &$out;
            foreach ($segments as $i => $seg) {
                if ($i === count($segments) - 1) {
                    $ref[$seg] = $value;
                } else {
                    if (!isset($ref[$seg]) || !is_array($ref[$seg])) {
                        $ref[$seg] = [];
                    }
                    $ref = &$ref[$seg];
                }
            }
        }

        return $out;
    }

    private static function deeplLang(string $locale): ?string
    {
        $locale = strtolower(trim($locale));
        $map = [
            'en' => 'EN',
            'nl' => 'NL',
            'de' => 'DE',
            'fr' => 'FR',
            'es' => 'ES',
            'it' => 'IT',
            'pl' => 'PL',
            'pt' => 'PT',
            'ru' => 'RU',
            'ja' => 'JA',
            'zh' => 'ZH',
        ];

        return $map[$locale] ?? strtoupper(substr($locale, 0, 2));
    }
}
