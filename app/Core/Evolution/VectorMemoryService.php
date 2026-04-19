<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Persistent cross-session vector memory.
 *
 * Stores arbitrary knowledge as TF-IDF vectors in JSON files.
 * Cosine similarity search — zero external dependencies.
 *
 * ─── Credit-saving mechanism ──────────────────────────────────────────────────
 *
 *  Before every cloud AI call, inject() enriches the prompt with the top-K
 *  most relevant knowledge entries from previous sessions. This means Claude
 *  never needs to re-learn what the framework already knows — shorter prompts,
 *  fewer tokens, lower cost.
 *
 * ─── Namespaces ───────────────────────────────────────────────────────────────
 *
 *  'global'   — cross-domain lessons (default)
 *  'debate'   — consensus results from DebateOrchestrator
 *  'bugfixes' — patched bugs with root causes
 *  'security' — security decisions (always injected for payment/auth tasks)
 *
 * ─── Usage ────────────────────────────────────────────────────────────────────
 *
 *   $mem = new VectorMemoryService('bugfixes');
 *   $mem->store("SQL injection in ListingModel line 42 — fixed by parameterised query", [
 *       'file' => 'ListingModel.php', 'severity' => 'high'
 *   ]);
 *
 *   $enriched = $mem->inject($prompt);   // prepends top-3 relevant entries
 *   $results  = $mem->search($query, 5); // returns scored results
 */
final class VectorMemoryService
{
    private const BASE_DIR     = '/var/www/html/data/evolution/vector_memory';
    private const RUST_BINARY  = '/var/www/html/data/evolution/evolution_vec';
    private const RUST_THRESHOLD = 5000; // use Rust subprocess above this entry count
    private const MAX_ENTRIES  = 10000;
    private const DEDUP_THRESH = 0.92;
    private const MIN_WORD_LEN = 3;

    /**
     * In-process entry cache — persists between requests in FrankenPHP worker mode.
     * Key = namespace, value = loaded entries array.
     * This is the biggest latency win: disk is read once per worker lifetime.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private static array $processCache = [];

    public function __construct(
        private readonly string $namespace = 'global',
        private readonly string $baseDir   = self::BASE_DIR
    ) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Store a knowledge entry. Skips near-duplicates (cosine ≥ 0.92).
     *
     * @param array<string, mixed> $meta
     */
    public function store(string $text, array $meta = []): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        $entries = $this->load();

        if ($this->isDuplicate($text, $entries)) {
            return false;
        }

        $entries[] = [
            'text'      => $text,
            'vector'    => $this->vectorize($text),
            'meta'      => $meta,
            'stored_at' => date('c'),
        ];

        if (count($entries) > self::MAX_ENTRIES) {
            $entries = array_slice($entries, -self::MAX_ENTRIES);
        }

        self::$processCache[$this->namespace] = $entries;
        $this->save($entries);

        return true;
    }

    /**
     * Semantic search — returns top-K entries with similarity scores.
     *
     * @return list<array{text: string, meta: array, stored_at: string, _score: float}>
     */
    public function search(string $query, int $topK = 5, float $minScore = 0.10): array
    {
        $entries = $this->load();
        if (empty($entries)) {
            return [];
        }

        $queryVec = $this->vectorize($query);
        if (empty($queryVec)) {
            return [];
        }

        // Route to Rust binary for large corpora; fall back to PHP otherwise
        if (count($entries) >= self::RUST_THRESHOLD) {
            $rustResult = $this->searchViaRust($queryVec, $entries, $topK, $minScore);
            if ($rustResult !== null) {
                return $rustResult;
            }
        }

        // PHP path (fast for <5000 entries)
        $scored = [];
        foreach ($entries as $entry) {
            $score = $this->cosine($queryVec, $entry['vector'] ?? []);
            if ($score >= $minScore) {
                $scored[] = [
                    'text'      => $entry['text'],
                    'meta'      => $entry['meta'] ?? [],
                    'stored_at' => $entry['stored_at'] ?? '',
                    '_score'    => round($score, 4),
                ];
            }
        }

        usort($scored, static fn ($a, $b) => $b['_score'] <=> $a['_score']);

        return array_slice($scored, 0, $topK);
    }

    /**
     * Cross-Memory Distillation — compress near-duplicate entries into one.
     * Removes entries that have cosine ≥ threshold with a newer entry.
     * Call nightly to keep the memory compact and credit-efficient.
     *
     * @return array{before: int, after: int, removed: int, threshold: float}
     */
    public function distill(float $similarityThreshold = 0.75): array
    {
        $entries = $this->load();
        $before  = count($entries);

        if ($before < 2) {
            return ['before' => $before, 'after' => $before, 'removed' => 0, 'threshold' => $similarityThreshold];
        }

        // Process newest-first: keep an entry only if no already-kept entry is too similar
        $kept        = [];
        $keptVectors = [];

        foreach (array_reverse($entries) as $entry) {
            $vec     = $entry['vector'] ?? [];
            $isDup   = false;

            foreach ($keptVectors as $kv) {
                if ($this->cosine($vec, $kv) >= $similarityThreshold) {
                    $isDup = true;
                    break;
                }
            }

            if (!$isDup) {
                $kept[]        = $entry;
                $keptVectors[] = $vec;
            }
        }

        $entries = array_reverse($kept); // restore chronological order
        self::$processCache[$this->namespace] = $entries;
        $this->save($entries);

        return [
            'before'    => $before,
            'after'     => count($entries),
            'removed'   => $before - count($entries),
            'threshold' => $similarityThreshold,
        ];
    }

    /**
     * Inject relevant memory context at the top of a prompt.
     * Returns the original prompt unchanged when memory is empty.
     */
    public function inject(string $prompt, int $topK = 3, float $minScore = 0.12): string
    {
        $results = $this->search($prompt, $topK, $minScore);
        if (empty($results)) {
            return $prompt;
        }

        $block = "=== Framework Memory (top-{$topK} relevant lessons) ===\n";
        foreach ($results as $i => $r) {
            $score = number_format($r['_score'] * 100, 0);
            $block .= ($i + 1) . ". [{$score}%] " . trim($r['text']) . "\n";
        }
        $block .= "=== End Memory ===\n\n";

        return $block . $prompt;
    }

    /** Number of stored entries in this namespace. */
    public function count(): int
    {
        return count($this->load());
    }

    /**
     * Most recent stored entries (chronological order), for Strategist / trading_nn snapshots.
     *
     * @return list<array{text: string, stored_at: string, meta: array<string, mixed>}>
     */
    public function recentEntries(int $limit = 8): array
    {
        if ($limit < 1) {
            return [];
        }
        $entries = $this->load();
        if ($entries === []) {
            return [];
        }
        $slice = array_slice($entries, -$limit);
        $out   = [];
        foreach ($slice as $e) {
            $out[] = [
                'text'      => (string) ($e['text'] ?? ''),
                'stored_at' => (string) ($e['stored_at'] ?? ''),
                'meta'      => (array) ($e['meta'] ?? []),
            ];
        }

        return $out;
    }

    /** @return array{namespace: string, entries: int, path: string, size_kb: float} */
    public function stats(): array
    {
        $path = $this->path();
        return [
            'namespace' => $this->namespace,
            'entries'   => $this->count(),
            'path'      => $path,
            'size_kb'   => is_file($path) ? round(filesize($path) / 1024, 1) : 0.0,
        ];
    }

    public function clear(): void
    {
        self::$processCache[$this->namespace] = [];
        $this->save([]);
    }

    /** Invalidate the in-process cache for a namespace (or all namespaces). Used in benchmarks. */
    public static function clearProcessCache(?string $namespace = null): void
    {
        if ($namespace !== null) {
            unset(self::$processCache[$namespace]);
        } else {
            self::$processCache = [];
        }
    }

    /**
     * Decay-based distillation — archives entries older than $maxAgeDays.
     * Archived entries go to {ns}_archive.json and can be restored manually.
     *
     * This is the "forgetting" mechanism: knowledge that is old is moved out
     * of the active memory to keep prompts fast and relevant.
     *
     * @return array{before: int, kept: int, archived: int, cutoff: string, max_age_days: int}
     */
    public function decayDistill(int $maxAgeDays = 180): array
    {
        $entries = $this->load();
        $before  = count($entries);
        $cutoff  = new \DateTime("-{$maxAgeDays} days");

        $keep     = [];
        $archived = [];

        foreach ($entries as $entry) {
            $storedAt = trim((string) ($entry['stored_at'] ?? ''));

            if ($storedAt === '') {
                $keep[] = $entry;
                continue;
            }

            try {
                $stored = new \DateTime($storedAt);
                if ($stored < $cutoff) {
                    $archived[] = $entry;
                } else {
                    $keep[] = $entry;
                }
            } catch (\Throwable) {
                $keep[] = $entry;
            }
        }

        self::$processCache[$this->namespace] = $keep;
        $this->save($keep);

        if (!empty($archived)) {
            $this->appendToArchive($archived);
        }

        return [
            'before'       => $before,
            'kept'         => count($keep),
            'archived'     => count($archived),
            'cutoff'       => $cutoff->format('Y-m-d'),
            'max_age_days' => $maxAgeDays,
        ];
    }

    /**
     * Pre-warm the in-process cache for multiple namespaces in one call.
     * After this call, subsequent search() / inject() calls on these
     * namespaces are served entirely from RAM — zero disk reads.
     *
     * Designed for PredictiveVectorLoader::preload() and worker boot.
     */
    public static function preload(string ...$namespaces): void
    {
        foreach (array_unique($namespaces) as $ns) {
            if ($ns !== '') {
                (new self($ns))->count();
            }
        }
    }

    /**
     * @return array{namespace: string, entries: int, path: string, archive_entries: int, archive_kb: float}
     */
    public function fullStats(): array
    {
        $base    = $this->stats();
        $archive = $this->archivePath();

        $archiveEntries = 0;
        $archiveKb      = 0.0;

        if (is_file($archive)) {
            $data           = json_decode((string) file_get_contents($archive), true);
            $archiveEntries = is_array($data) ? count($data) : 0;
            $archiveKb      = round(filesize($archive) / 1024, 1);
        }

        return array_merge($base, [
            'archive_entries' => $archiveEntries,
            'archive_kb'      => $archiveKb,
        ]);
    }

    // ── TF-IDF vectorization ──────────────────────────────────────────────────

    /**
     * Produces a term-frequency vector (word → normalised frequency).
     *
     * @return array<string, float>
     */
    private function vectorize(string $text): array
    {
        $text  = strtolower((string) preg_replace('/[^a-z0-9\s]/i', ' ', $text));
        $words = array_filter(
            explode(' ', $text),
            static fn ($w) => strlen($w) >= self::MIN_WORD_LEN
        );

        if (empty($words)) {
            return [];
        }

        /** @var array<string, int> $freq */
        $freq  = array_count_values($words);
        $total = array_sum($freq);

        $vec = [];
        foreach ($freq as $word => $count) {
            $vec[$word] = $count / $total;
        }

        return $vec;
    }

    /**
     * Cosine similarity between two sparse TF vectors.
     *
     * @param array<string, float> $a
     * @param array<string, float> $b
     */
    private function cosine(array $a, array $b): float
    {
        if (empty($a) || empty($b)) {
            return 0.0;
        }

        $dot  = 0.0;
        $magA = 0.0;
        $magB = 0.0;

        foreach ($a as $k => $v) {
            $magA += $v * $v;
            if (isset($b[$k])) {
                $dot += $v * $b[$k];
            }
        }
        foreach ($b as $v) {
            $magB += $v * $v;
        }

        $denom = sqrt($magA) * sqrt($magB);

        return $denom > 0.0 ? min(1.0, $dot / $denom) : 0.0;
    }

    /**
     * Check last 100 entries for near-duplicates to avoid redundant storage.
     *
     * @param array<int, array<string, mixed>> $entries
     */
    private function isDuplicate(string $text, array $entries): bool
    {
        if (empty($entries)) {
            return false;
        }

        $vec   = $this->vectorize($text);
        $check = array_slice($entries, -100);

        foreach ($check as $entry) {
            if ($this->cosine($vec, $entry['vector'] ?? []) >= self::DEDUP_THRESH) {
                return true;
            }
        }

        return false;
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function load(): array
    {
        // Return in-process cache when available (zero-latency in FrankenPHP worker mode)
        if (isset(self::$processCache[$this->namespace])) {
            return self::$processCache[$this->namespace];
        }

        $path = $this->path();
        if (!is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);
        $entries = is_array($data) ? $data : [];

        self::$processCache[$this->namespace] = $entries;

        return $entries;
    }

    /**
     * Rust binary subprocess bridge — used for corpora >= RUST_THRESHOLD entries.
     * Binary reads JSON from stdin, writes scored results to stdout.
     * Falls back gracefully to null when binary is not available.
     *
     * @param  array<string, float>       $queryVec
     * @param  list<array<string, mixed>> $entries
     * @return list<array{text: string, meta: array, stored_at: string, _score: float}>|null
     */
    private function searchViaRust(array $queryVec, array $entries, int $topK, float $minScore): ?array
    {
        $binary = self::RUST_BINARY;
        if (!is_file($binary) || !is_executable($binary)) {
            return null;
        }

        $corpus = [];
        foreach ($entries as $i => $entry) {
            $corpus[] = ['id' => $i, 'vector' => $entry['vector'] ?? []];
        }

        $input = json_encode([
            'query'     => $queryVec,
            'corpus'    => $corpus,
            'top_k'     => $topK,
            'min_score' => $minScore,
        ]);

        if ($input === false) {
            return null;
        }

        $pipes   = [];
        $process = proc_open(
            escapeshellcmd($binary),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (!is_resource($process)) {
            return null;
        }

        fwrite($pipes[0], $input);
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0 || !is_string($output) || $output === '') {
            return null;
        }

        $scores = json_decode($output, true);
        if (!is_array($scores)) {
            return null;
        }

        $result = [];
        foreach ($scores as $s) {
            $idx = (int) ($s['id'] ?? -1);
            if (isset($entries[$idx])) {
                $result[] = [
                    'text'      => $entries[$idx]['text'],
                    'meta'      => $entries[$idx]['meta'] ?? [],
                    'stored_at' => $entries[$idx]['stored_at'] ?? '',
                    '_score'    => round((float) ($s['score'] ?? 0), 4),
                ];
            }
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $entries */
    private function save(array $entries): void
    {
        $dir = $this->baseDir;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->path(),
            json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function path(): string
    {
        $ns = preg_replace('/[^a-z0-9_-]/i', '_', $this->namespace);

        return rtrim($this->baseDir, '/') . '/' . $ns . '.json';
    }

    private function archivePath(): string
    {
        $ns = preg_replace('/[^a-z0-9_-]/i', '_', $this->namespace);

        return rtrim($this->baseDir, '/') . '/' . $ns . '_archive.json';
    }

    /** @param list<array<string, mixed>> $entries */
    private function appendToArchive(array $entries): void
    {
        $path    = $this->archivePath();
        $existing = [];

        if (is_file($path)) {
            $data     = json_decode((string) file_get_contents($path), true);
            $existing = is_array($data) ? $data : [];
        }

        $merged = array_merge($existing, $entries);

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $path,
            json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /** @return list<string> Namespaces currently warm in the in-process cache. */
    public static function warmedNamespaces(): array
    {
        return array_keys(self::$processCache);
    }
}
