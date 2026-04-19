<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

/**
 * Append-only log for evolve:trade heartbeat (cron). Path: storage/logs/trading_heartbeat.log
 */
final class TradingHeartbeatLogger
{
    private string $filePath;

    public function __construct(string $projectRoot)
    {
        $root = rtrim($projectRoot, '/\\');
        $this->filePath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'trading_heartbeat.log';
    }

    public function path(): string
    {
        return $this->filePath;
    }

    public function append(string $line): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        file_put_contents($this->filePath, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function appendJson(array $payload): void
    {
        $payload['logged_at'] = $payload['logged_at'] ?? date('c');
        $this->append((string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
