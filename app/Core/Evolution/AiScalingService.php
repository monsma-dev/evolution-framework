<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Evolution\Wallet\BaseRpcService;

/**
 * AiScalingService - Profit-driven AI model tier selection
 * 
 * Automatically selects AI model tier based on:
 * 1. Current wallet balance (profitability)
 * 2. Task complexity analysis
 * 3. Daily budget limits
 * 
 * 20% of profit allocated to AI tokens per configuration
 */
final class AiScalingService
{
    private string $basePath;
    private array $config;
    private ?BaseRpcService $rpc;
    
    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2));
        $this->config = $this->loadConfig();
        $this->rpc = BaseRpcService::forTradingFromEvolutionJson($this->basePath);
    }
    
    /**
     * Get the optimal model for a task based on complexity and budget
     */
    public function selectModel(string $task, string $preferredTier = 'auto'): array
    {
        $balance = $this->getWalletBalance();
        $tier = $preferredTier === 'auto' ? $this->determineTier($task, $balance) : $preferredTier;
        
        // Check if we can afford this tier
        if (!$this->canAffordTier($tier, $balance)) {
            $tier = $this->fallbackTier($balance);
        }
        
        $tierConfig = $this->config['model_tiers'][$tier] ?? $this->config['model_tiers']['free'];
        
        return [
            'tier' => $tier,
            'models' => $tierConfig['models'],
            'max_tokens' => $tierConfig['max_tokens_per_request'],
            'daily_budget_eth' => $tierConfig['daily_budget_eth'] ?? 0,
            'balance_eth' => $balance,
            'task_complexity' => $this->analyzeComplexity($task),
            'reasoning' => $this->getReasoning($tier, $balance, $task)
        ];
    }
    
    /**
     * Determine appropriate tier based on task complexity and wallet balance
     */
    private function determineTier(string $task, float $balance): string
    {
        $complexity = $this->analyzeComplexity($task);
        $tiers = $this->config['model_tiers'];
        
        // Premium tier for complex tasks with good balance
        if ($complexity >= $this->config['complexity_detection']['complex_threshold'] 
            && $balance >= ($tiers['premium']['trigger_balance_eth'] ?? 0.1)) {
            return 'premium';
        }
        
        // Standard tier for medium complexity or good balance
        if (($complexity >= $this->config['complexity_detection']['simple_threshold'] 
             && $balance >= ($tiers['standard']['trigger_balance_eth'] ?? 0.05))
            || $balance >= ($tiers['standard']['trigger_balance_eth'] ?? 0.05)) {
            return 'standard';
        }
        
        // Economy tier for simple tasks with modest balance
        if ($balance >= ($tiers['economy']['trigger_balance_eth'] ?? 0.01)) {
            return 'economy';
        }
        
        // Free tier (local Ollama) as fallback
        return 'free';
    }
    
    /**
     * Analyze task complexity score (0-1000+)
     */
    public function analyzeComplexity(string $task): int
    {
        $score = 0;
        $taskLower = strtolower($task);
        
        // Check for complex keywords
        $complexKeywords = $this->config['complexity_detection']['keywords']['complex'] ?? [];
        foreach ($complexKeywords as $keyword) {
            if (str_contains($taskLower, $keyword)) {
                $score += 150;
            }
        }
        
        // Check for simple keywords (reduce score)
        $simpleKeywords = $this->config['complexity_detection']['keywords']['simple'] ?? [];
        foreach ($simpleKeywords as $keyword) {
            if (str_contains($taskLower, $keyword)) {
                $score -= 50;
            }
        }
        
        // Length factor (longer = more complex)
        $length = strlen($task);
        if ($length > 500) {
            $score += 100;
        } elseif ($length > 200) {
            $score += 50;
        }
        
        // Code block detection (more code = more complex)
        $codeBlocks = substr_count($task, '```');
        $score += $codeBlocks * 50;
        
        return max(0, $score);
    }
    
    /**
     * Check if we can afford a specific tier today
     */
    private function canAffordTier(string $tier, float $balance): bool
    {
        $tierConfig = $this->config['model_tiers'][$tier] ?? null;
        if (!$tierConfig) {
            return false;
        }
        
        $dailyBudget = $tierConfig['daily_budget_eth'] ?? 0;
        $triggerBalance = $tierConfig['trigger_balance_eth'] ?? 0;
        
        // Check minimum balance requirement
        if ($balance < $triggerBalance) {
            return false;
        }
        
        // Check daily budget not exceeded
        $stats = $this->getDailyStats();
        if ($stats['eth_spent'] >= $dailyBudget) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Find best fallback tier based on current balance
     */
    private function fallbackTier(float $balance): string
    {
        $tiers = ['standard', 'economy', 'free'];
        
        foreach ($tiers as $tier) {
            if ($this->canAffordTier($tier, $balance)) {
                return $tier;
            }
        }
        
        return 'free';
    }
    
    /**
     * Get current wallet balance in ETH
     */
    private function getWalletBalance(): float
    {
        $walletFile = $this->basePath . '/data/evolution/wallet/wallet.json';
        if (!is_file($walletFile)) {
            return 0.0;
        }
        
        $wallet = json_decode(file_get_contents($walletFile), true);
        $address = $wallet['address'] ?? '';
        
        if (!$address) {
            return 0.0;
        }
        
        return $this->rpc->getBalance($address);
    }
    
    /**
     * Get daily usage stats
     */
    public function getDailyStats(): array
    {
        $statsFile = $this->basePath . '/data/evolution/ai_scaling_stats.json';
        
        if (!is_file($statsFile)) {
            return $this->config['daily_stats'];
        }
        
        $stats = json_decode(file_get_contents($statsFile), true);
        
        // Reset if new day
        $lastReset = strtotime($stats['last_reset'] ?? '1970-01-01');
        if (date('Y-m-d', $lastReset) !== date('Y-m-d')) {
            $stats = [
                'tokens_used' => 0,
                'eth_spent' => 0,
                'requests_made' => 0,
                'tier_switches' => 0,
                'last_reset' => date('c')
            ];
            file_put_contents($statsFile, json_encode($stats, JSON_PRETTY_PRINT), LOCK_EX);
        }
        
        return $stats;
    }
    
    /**
     * Record AI usage for budget tracking
     */
    public function recordUsage(string $tier, int $tokens, float $ethCost): void
    {
        $stats = $this->getDailyStats();
        $stats['tokens_used'] += $tokens;
        $stats['eth_spent'] += $ethCost;
        $stats['requests_made']++;
        
        $statsFile = $this->basePath . '/data/evolution/ai_scaling_stats.json';
        file_put_contents($statsFile, json_encode($stats, JSON_PRETTY_PRINT), LOCK_EX);
    }
    
    /**
     * Calculate profit allocation from payments received
     */
    public function calculateProfitAllocation(float $paymentEth): array
    {
        $allocation = $this->config['profit_allocation'];
        
        return [
            'ai_tokens' => $paymentEth * ($allocation['ai_token_budget_percent'] / 100),
            'reserve' => $paymentEth * ($allocation['reserve_percent'] / 100),
            'operations' => $paymentEth * ($allocation['operations_percent'] / 100),
            'total' => $paymentEth
        ];
    }
    
    /**
     * Get scaling reasoning for logging/display
     */
    private function getReasoning(string $tier, float $balance, string $task): string
    {
        $complexity = $this->analyzeComplexity($task);
        $tierConfig = $this->config['model_tiers'][$tier];
        
        return sprintf(
            "Selected %s tier (balance: %.4f ETH, complexity: %d). Daily budget: %.4f ETH. Models: %s",
            $tier,
            $balance,
            $complexity,
            $tierConfig['daily_budget_eth'] ?? 0,
            implode(', ', $tierConfig['models'])
        );
    }
    
    /**
     * Get scaling report for dashboard
     */
    public function getScalingReport(): array
    {
        $balance = $this->getWalletBalance();
        $stats = $this->getDailyStats();
        
        return [
            'current_balance_eth' => $balance,
            'current_balance_usd_approx' => $balance * 3200, // Rough ETH price
            'today_stats' => $stats,
            'available_tiers' => $this->getAvailableTiers($balance),
            'recommended_tier' => $this->determineTier('medium complexity task', $balance),
            'scaling_enabled' => $this->config['enabled'] ?? false
        ];
    }
    
    /**
     * Get list of tiers available with current balance
     */
    private function getAvailableTiers(float $balance): array
    {
        $available = [];
        
        foreach ($this->config['model_tiers'] as $tier => $config) {
            $trigger = $config['trigger_balance_eth'] ?? 0;
            $available[$tier] = [
                'available' => $balance >= $trigger,
                'min_balance' => $trigger,
                'daily_budget' => $config['daily_budget_eth'] ?? 0,
                'models' => $config['models']
            ];
        }
        
        return $available;
    }
    
    private function loadConfig(): array
    {
        if (!defined('BASE_PATH')) {
            return ['enabled' => false];
        }
        
        $cfg = BASE_PATH . '/config/evolution.json';
        if (!is_file($cfg)) {
            return ['enabled' => false];
        }
        
        $data = json_decode(file_get_contents($cfg), true);
        return $data['ai_scaling'] ?? ['enabled' => false];
    }
}
