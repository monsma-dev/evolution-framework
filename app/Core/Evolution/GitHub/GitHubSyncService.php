<?php

declare(strict_types=1);

namespace App\Core\Evolution\GitHub;

/**
 * GitHubSyncService — Syncs the "Open Core" to the public GitHub repository.
 *
 * Only syncs paths listed in evolution.json github.sync.open_core_paths.
 * Excludes paths from github.sync.exclude_paths.
 *
 * Sync log: storage/evolution/github/sync_log.jsonl
 */
final class GitHubSyncService
{
    private const SYNC_LOG   = 'storage/evolution/github/sync_log.jsonl';
    private const STATE_FILE = 'storage/evolution/github/sync_state.json';

    private EvolutionGitHubService $github;
    private string $basePath;
    private string $branch;
    private array  $includePaths;
    private array  $excludePaths;

    public function __construct(?EvolutionGitHubService $github = null, ?string $basePath = null)
    {
        $this->github    = $github ?? new EvolutionGitHubService();
        $this->basePath  = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $cfg             = $this->loadConfig();
        $this->branch    = $cfg['branch'] ?? 'main';
        $this->includePaths = $cfg['open_core_paths'] ?? [];
        $this->excludePaths = $cfg['exclude_paths'] ?? [];
    }

    /**
     * Run a full sync. Returns ['synced'=>n, 'skipped'=>n, 'errors'=>n, 'files'=>[]].
     */
    public function sync(bool $dryRun = false): array
    {
        if (!$this->github->isConfigured()) {
            return ['error' => 'GitHub not configured. Run evolve:github-setup init'];
        }

        $files      = $this->collectFiles();
        $synced     = 0;
        $skipped    = 0;
        $errors     = 0;
        $syncedList = [];

        foreach ($files as $localPath => $repoPath) {
            if (!is_readable($localPath)) {
                $skipped++;
                continue;
            }

            $content = (string) file_get_contents($localPath);
            $hash    = md5($content);

            if ($this->alreadySynced($repoPath, $hash)) {
                $skipped++;
                continue;
            }

            if (!$dryRun) {
                try {
                    $msg = "chore(sync): update {$repoPath}";
                    $this->github->putFile($repoPath, $content, $msg, $this->branch);
                    $this->markSynced($repoPath, $hash);
                    $synced++;
                    $syncedList[] = $repoPath;
                    $this->log($repoPath, 'synced', $hash);
                    usleep(300_000); // 300ms between writes — respect secondary rate limits
                } catch (\Throwable $e) {
                    $this->log($repoPath, 'error', $hash, $e->getMessage());
                    $errors++;
                }
            } else {
                $synced++;
                $syncedList[] = $repoPath . ' (dry-run)';
            }
        }

        return [
            'synced'  => $synced,
            'skipped' => $skipped,
            'errors'  => $errors,
            'files'   => $syncedList,
            'dry_run' => $dryRun,
            'branch'  => $this->branch,
            'ran_at'  => date('c'),
        ];
    }

    /** Returns list of files that would be synced. */
    public function preview(): array
    {
        return $this->collectFiles();
    }

    /** Returns recent sync log entries. */
    public function recentLog(int $limit = 30): array
    {
        $file = $this->basePath . '/' . self::SYNC_LOG;
        if (!is_file($file)) {
            return [];
        }
        $lines = array_filter(explode("\n", (string) file_get_contents($file)));
        $items = [];
        foreach ($lines as $line) {
            $d = json_decode($line, true);
            if (is_array($d)) {
                $items[] = $d;
            }
        }
        return array_slice(array_reverse($items), 0, $limit);
    }

    private function collectFiles(): array
    {
        $result = [];
        foreach ($this->includePaths as $includePath) {
            $full = $this->basePath . '/' . ltrim($includePath, '/');
            if (is_file($full)) {
                if (!$this->isExcluded($includePath)) {
                    $result[$full] = ltrim($includePath, '/');
                }
            } elseif (is_dir($full)) {
                foreach ($this->scanDir($full) as $file) {
                    $relative = ltrim(str_replace($this->basePath . '/', '', $file), '/');
                    if (!$this->isExcluded($relative)) {
                        $result[$file] = $relative;
                    }
                }
            }
        }
        return $result;
    }

    private function scanDir(string $dir): array
    {
        $files   = [];
        $entries = scandir($dir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $files = array_merge($files, $this->scanDir($path));
            } elseif (is_file($path) && $this->isAllowedExtension($path)) {
                $files[] = $path;
            }
        }
        return $files;
    }

    private function isAllowedExtension(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['php', 'json', 'md', 'twig', 'txt', 'sh', 'yml', 'yaml'], true);
    }

    private function isExcluded(string $path): bool
    {
        foreach ($this->excludePaths as $ex) {
            if (str_starts_with($path, ltrim($ex, '/'))) {
                return true;
            }
        }
        return false;
    }

    private function alreadySynced(string $repoPath, string $hash): bool
    {
        $state = $this->loadState();
        return isset($state[$repoPath]) && $state[$repoPath] === $hash;
    }

    private function markSynced(string $repoPath, string $hash): void
    {
        $state = $this->loadState();
        $state[$repoPath] = $hash;
        $file = $this->basePath . '/' . self::STATE_FILE;
        $this->ensureDir(dirname($file));
        file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function loadState(): array
    {
        $file = $this->basePath . '/' . self::STATE_FILE;
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function log(string $path, string $status, string $hash, string $error = ''): void
    {
        $file = $this->basePath . '/' . self::SYNC_LOG;
        $this->ensureDir(dirname($file));
        $entry = [
            'path'      => $path,
            'status'    => $status,
            'hash'      => $hash,
            'error'     => $error,
            'logged_at' => date('c'),
        ];
        file_put_contents($file, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function loadConfig(): array
    {
        if (!defined('BASE_PATH')) {
            return [];
        }
        $file = BASE_PATH . '/config/evolution.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? ($data['github']['sync'] ?? []) : [];
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
    }
}
