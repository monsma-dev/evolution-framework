<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Evolution\Trading\SentimentAnalyzer;
use App\Core\Evolution\Trading\TradingService;
use App\Core\Evolution\Trading\XTwitterSentimentBridge;
use Psr\Container\ContainerInterface;

/**
 * Aggregaat voor het Evolution-loop dashboard: schrijfrechten, agent-"gedachten", sentiment, code-log.
 */
final class EvolutionLoopStatusService
{
    public function __construct(
        private readonly Config $config,
        private readonly string $basePath,
        private readonly ?ContainerInterface $container = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $src = rtrim($this->basePath, '/\\') . '/src';
        $writable = is_dir($src) && is_writable($src);
        $probe = false;
        if ($writable) {
            $probeFile = $src . '/.evolution_write_probe';
            $probe = @file_put_contents($probeFile, (string)gmdate('c')) !== false;
            if ($probe) {
                @unlink($probeFile);
            }
        }

        $ghost = $this->safeGhostStatus();
        $intentTail = $this->intentTail(12);
        $codeToday = AgentCodeChangeLogger::todayEntries($this->basePath);
        $master = $this->readJsonFile($this->basePath . '/data/evolution/master_last_opinion.json');

        $trading = null;
        try {
            $evo = $this->config->get('evolution', []);
            $ts = new TradingService(is_array($evo) ? $evo : [], $this->basePath, $this->container);
            $trading = $ts->status();
        } catch (\Throwable) {
        }

        $newsSent = (new SentimentAnalyzer(null, $this->basePath))->currentSentiment(false);
        $xBridge = new XTwitterSentimentBridge($this->config, $this->basePath);

        return [
            'ts' => gmdate('c'),
            'architect_src' => [
                'src_dir' => $src,
                'is_writable' => $writable,
                'probe_write_ok' => $probe,
            ],
            'ghost_mode' => $ghost,
            'intent_log_tail' => $intentTail,
            'code_changes_today' => $codeToday,
            'master_opinion' => $master,
            'trading_status' => $trading ? [
                'chain_id' => $trading['chain_id'] ?? null,
                'network_label' => $trading['network_label'] ?? null,
                'mode' => $trading['mode'] ?? null,
                'signal' => $trading['signal'] ?? null,
            ] : null,
            'sentiment_news' => $newsSent,
            'x_sentiment_bridge' => $xBridge->snapshot(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function safeGhostStatus(): array
    {
        try {
            return EvolutionGhostMode::status();
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function intentTail(int $n): array
    {
        $path = $this->basePath . '/data/evolution/intent_log.jsonl';
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $out = [];
        foreach (array_slice(array_reverse($lines), 0, $n) as $ln) {
            $j = json_decode($ln, true);
            if (is_array($j)) {
                $out[] = $j;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJsonFile(string $path): ?array
    {
        if (!is_readable($path)) {
            return null;
        }
        $j = json_decode((string)file_get_contents($path), true);

        return is_array($j) ? $j : null;
    }
}
