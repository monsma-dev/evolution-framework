<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use PDO;

/**
 * SovereignAiRouter — intelligent API vs. local AI routing.
 *
 * Routing strategy:
 *   HIGH / PREMIUM tasks  → DeepSeek API (R1 reasoning or V3 chat)
 *   LOW / LIGHT tasks     → Ollama local model (deepseek-r1:1.5b)
 *   API failure / timeout → automatic fallback to Ollama
 *
 * This ensures zero downtime when the DeepSeek API is unavailable while keeping
 * heavy reasoning tasks on the full cloud model.
 */
final class SovereignAiRouter
{
    private const OLLAMA_HOST_CONFIG = 'evolution.sovereign.ollama_host';
    private const OLLAMA_MODEL_CONFIG = 'evolution.sovereign.ollama_model';
    private const LOCAL_TASKS_CONFIG  = 'evolution.sovereign.local_tasks';

    private DeepSeekClient   $deepSeek;
    private OllamaClient     $ollama;
    private bool $ollamaEnabled;
    private ?AiCreditMonitor $monitor = null;

    /** @var list<string> task types that always use local Ollama */
    private array $localTasks;

    private string $lastRoute = '';
    private bool   $ecoMode   = false;
    private ?BudgetGuardService $budgetGuard = null;

    public function __construct(private readonly Config $config)
    {
        $apiKey = trim((string)($config->get('ai.deepseek.api_key') ?? ''));
        $this->deepSeek = new DeepSeekClient($apiKey);

        $ollamaHost  = trim((string)($config->get(self::OLLAMA_HOST_CONFIG) ?? ''));
        $ollamaModel = trim((string)($config->get(self::OLLAMA_MODEL_CONFIG) ?? ''));
        $this->ollama = new OllamaClient($ollamaHost, $ollamaModel);

        $this->ollamaEnabled = filter_var(
            $config->get('evolution.sovereign.enabled') ?? true,
            FILTER_VALIDATE_BOOL
        );

        $configured = $config->get(self::LOCAL_TASKS_CONFIG);
        $this->localTasks = is_array($configured) ? array_map('strval', $configured) : [
            'formatting', 'classification', 'summarize', 'intent_score', 'validation',
        ];
    }

    /**
     * Inject PDO to enable the €0.10 Safe-Spend BudgetGuard.
     * Without a DB connection the guard still logs to budget_alerts.log but
     * cannot persist pending approvals — all tasks still route normally.
     */
    public function setDb(PDO $db): void
    {
        $this->budgetGuard = new BudgetGuardService($this->config, $db);
    }

    /**
     * Inject AiCreditMonitor to enable monthly budget eco-mode.
     * When the monthly €20 cap is reached, all tasks route to local Ollama.
     */
    public function setMonitor(AiCreditMonitor $monitor): void
    {
        $this->monitor = $monitor;
        $this->ecoMode = $monitor->isMonthlyBudgetExhausted();
    }

    /**
     * True when monthly budget is exhausted and all calls are routed to Ollama.
     */
    public function isInEcoMode(): bool
    {
        return $this->ecoMode;
    }

    // ─── Public API ──────────────────────────────────────────────────────────────

    /**
     * Route a completion request to the best available model.
     *
     * @param string      $taskType   e.g. 'strategy', 'intent_score', 'formatting'
     * @param string      $system     System prompt
     * @param array       $messages   Chat messages [{role, content}]
     * @param int         $maxTokens  Max response tokens
     * @param string|null $model      Force a specific DeepSeek model (null = auto)
     */
    public function complete(
        string  $taskType,
        string  $system,
        array   $messages,
        int     $maxTokens = 2048,
        ?string $model = null,
        array   $ctx = []
    ): array {
        // Eco mode: monthly budget exhausted — all tasks go to local Ollama
        if ($this->ecoMode && $this->ollamaEnabled) {
            $result = $this->tryOllama($system, $messages, $maxTokens);
            $this->lastRoute = 'ollama_eco_mode';
            $result['eco_mode'] = true;
            return $result;
        }

        // ── Safe-Spend Gate: €0.10 per-task preflight ────────────────────────
        if ($this->budgetGuard !== null) {
            $dsModel = $model ?? DeepSeekClient::MODEL_CHAT;
            $estimatedInputTokens = (int)ceil((strlen($system) + array_sum(array_map(
                fn($m) => strlen((string)($m['content'] ?? '')), $messages
            ))) / 4);

            $preflight = $this->budgetGuard->preflightCheck(
                $taskType, $dsModel, $estimatedInputTokens, $maxTokens, $ctx
            );

            if ($preflight['action'] === 'reroute_ollama' && $this->ollamaEnabled) {
                $result = $this->tryOllama($system, $messages, $maxTokens);
                $this->lastRoute = 'ollama_budget_guard';
                $result['budget_guard'] = $preflight;
                return $result;
            }

            if ($preflight['action'] === 'pending_approval') {
                $this->lastRoute = 'pending_approval';
                return [
                    'ok'           => false,
                    'text'         => '',
                    'route'        => 'pending_approval',
                    'budget_guard' => $preflight,
                    'error'        => sprintf(
                        'Task queued for approval (est. €%.4f > €%.2f threshold). Task ID: %s',
                        $preflight['estimated_eur'],
                        $preflight['threshold_eur'],
                        $preflight['task_id'] ?? 'n/a'
                    ),
                ];
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        $useLocal = $this->shouldUseLocal($taskType);

        if (!$useLocal) {
            $result = $this->tryDeepSeekApi($system, $messages, $maxTokens, $model);
            if ($result['ok']) {
                $this->lastRoute = 'deepseek_api';
                return $result;
            }

            // API failed — fall back to Ollama if enabled
            if ($this->ollamaEnabled) {
                $result = $this->tryOllama($system, $messages, $maxTokens);
                $this->lastRoute = 'ollama_fallback';
                return $result;
            }

            return ['ok' => false, 'text' => '', 'route' => 'deepseek_api', 'error' => $result['error'] ?? 'API failed'];
        }

        // Local-first task
        if ($this->ollamaEnabled) {
            $result = $this->tryOllama($system, $messages, $maxTokens);
            if ($result['ok'] && $result['text'] !== '') {
                $this->lastRoute = 'ollama_local';
                return $result;
            }
        }

        // Ollama unavailable — fall back to API
        $result = $this->tryDeepSeekApi($system, $messages, $maxTokens, $model);
        $this->lastRoute = 'deepseek_api_fallback';
        return $result;
    }

    /**
     * Check if Ollama is reachable (non-blocking 2s probe).
     */
    public function isOllamaReachable(): bool
    {
        if (!$this->ollamaEnabled) {
            return false;
        }
        return $this->ollama->ping();
    }

    /**
     * Returns which route was used for the last complete() call.
     * Values: 'deepseek_api' | 'ollama_fallback' | 'ollama_local' | 'deepseek_api_fallback'
     */
    public function getLastRoute(): string
    {
        return $this->lastRoute;
    }

    /**
     * Status report for health checks.
     * @return array{deepseek_configured: bool, ollama_enabled: bool, ollama_reachable: bool, ollama_models: list<string>}
     */
    public function status(): array
    {
        $apiKey = trim((string)($this->config->get('ai.deepseek.api_key') ?? ''));
        $ollamaReachable = false;
        $models = [];

        if ($this->ollamaEnabled) {
            $models = $this->ollama->listModels();
            $ollamaReachable = $models !== [] || $this->ollama->ping();
        }

        return [
            'deepseek_configured' => $apiKey !== '',
            'ollama_enabled'      => $this->ollamaEnabled,
            'ollama_host'         => $this->ollama->getHost(),
            'ollama_model'        => $this->ollama->getDefaultModel(),
            'ollama_reachable'    => $ollamaReachable,
            'ollama_models'       => $models,
            'local_tasks'         => $this->localTasks,
        ];
    }

    // ─── Private helpers ─────────────────────────────────────────────────────────

    private function shouldUseLocal(string $taskType): bool
    {
        if (!$this->ollamaEnabled) {
            return false;
        }
        return in_array(strtolower($taskType), $this->localTasks, true);
    }

    /**
     * @return array{ok: bool, text: string, route: string, error?: string}
     */
    private function tryDeepSeekApi(string $system, array $messages, int $maxTokens, ?string $model): array
    {
        $apiKey = trim((string)($this->config->get('ai.deepseek.api_key') ?? ''));
        if ($apiKey === '') {
            return ['ok' => false, 'text' => '', 'route' => 'deepseek_api', 'error' => 'DEEPSEEK_API_KEY not configured'];
        }

        $this->deepSeek = new DeepSeekClient($apiKey);
        $dsModel = $model ?? DeepSeekClient::MODEL_CHAT;

        try {
            $text = $this->deepSeek->complete($system, $messages, $dsModel, $maxTokens);
            return ['ok' => true, 'text' => $text, 'route' => 'deepseek_api'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'text' => '', 'route' => 'deepseek_api', 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, text: string, route: string, error?: string}
     */
    private function tryOllama(string $system, array $messages, int $maxTokens): array
    {
        try {
            $text = $this->ollama->complete($system, $messages, null, $maxTokens);
            $ok   = $text !== '';
            return ['ok' => $ok, 'text' => $text, 'route' => 'ollama', 'http_status' => $this->ollama->getLastHttpStatus()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'text' => '', 'route' => 'ollama', 'error' => $e->getMessage()];
        }
    }
}
