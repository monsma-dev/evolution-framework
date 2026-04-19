<?php

declare(strict_types=1);

namespace App\Core\Evolution\Intelligence;

/**
 * Persistente brain state in storage/evolution/neural/brain_state.bin (EVN1 + JSON payload).
 */
final class NeuralBrainStateService
{
    private const MAGIC = 'EVN1';

    private string $path;

    private string $logPath;

    public function __construct(private readonly string $basePath)
    {
        $dir = rtrim($this->basePath, '/\\') . '/storage/evolution/neural';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $this->path    = $dir . '/brain_state.bin';
        $this->logPath = rtrim($this->basePath, '/\\') . '/storage/logs/neural_brain.log';
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function save(array $state): void
    {
        $state['saved_at'] = gmdate('c');
        $json   = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $len    = strlen($json);
        $binary = self::MAGIC . pack('N', $len) . $json;
        file_put_contents($this->path, $binary, LOCK_EX);
        $n   = strlen($binary);
        $sha = hash('sha256', $json);
        $rawBack = (string) file_get_contents($this->path);
        $ok      = $rawBack === $binary;
        if (!$ok && strlen($rawBack) >= 8 && substr($rawBack, 0, 4) === self::MAGIC) {
            $u2 = unpack('Nlen', substr($rawBack, 4, 4));
            $l2 = (int) ($u2['len'] ?? 0);
            $ok = $l2 === $len && substr($rawBack, 8, $l2) === $json;
        }
        $this->logLine(
            'brain_state.bin written bytes=' . $n . ' json_bytes=' . $len . ' sha256=' . $sha
            . ' roundtrip_read_ok=' . ($ok ? '1' : '0') . ' load_verified=' . ($ok ? '100%' : '0%'),
            $ok
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        if (!is_readable($this->path)) {
            return [];
        }
        $raw = (string) file_get_contents($this->path);
        $n   = strlen($raw);
        if ($n < 8) {
            $this->logLine('brain_state.bin too short (' . $n . ' bytes)', false);

            return [];
        }
        if (substr($raw, 0, 4) !== self::MAGIC) {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                return $j;
            }
            $this->logLine('invalid brain_state.bin header', false);

            return [];
        }
        $u = unpack('Nlen', substr($raw, 4, 4));
        $len = (int) ($u['len'] ?? 0);
        if ($len <= 0 || 8 + $len > $n) {
            $this->logLine('corrupt brain_state.bin length=' . $len, false);

            return [];
        }
        $json = substr($raw, 8, $len);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->logLine('brain_state JSON decode failed', false);

            return [];
        }

        return $data;
    }

    private function logLine(string $msg, bool $ok): void
    {
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = gmdate('c') . ' ' . ($ok ? '[OK]' : '[WARN]') . ' ' . $msg . "\n";
        @file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);
    }
}
