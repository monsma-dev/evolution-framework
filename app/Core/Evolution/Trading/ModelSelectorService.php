<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

/**
 * ModelSelectorService — Tier-based Claude model selection for trading + Evolution agents.
 *
 * Tiers (EUR-based wallet):
 *   < €50  : Haiku only (economy mode)
 *   ≥ €50  : Haiku default; up to 20% of daily API cost may go to Sonnet
 *   ≥ €100 : Sonnet for every DeepAnalysis buy-signal validation
 *   ≥ €150 : Opus 4.6 (Elite/Mythos-class) ALLEEN bij hoge volatiliteit of complexe taken
 *
 * Volatility Trigger:
 *   LOW  (< 2%)  : Standard/Sonnet — business as usual
 *   HIGH (≥ 3.5%): Premium/Sonnet 3.7 met extended thinking
 *   EXTREME (≥ 6%): Elite/Opus 4.6 — maximale intelligence
 *
 * Daily Elite guard: max €2,50/dag aan Opus 4.6 tokens (runaway thinking preventie)
 *
 * Daily cost ledger: storage/evolution/trading/model_cost_ledger.json
 */
final class ModelSelectorService
{
    public const MODEL_HAIKU         = 'claude-3-5-haiku-20241022';
    public const MODEL_HAIKU_45       = 'claude-haiku-4-5-20251001';
    public const MODEL_SONNET        = 'claude-3-7-sonnet-20250219';
    public const MODEL_SONNET_45     = 'claude-sonnet-4-5-20250929';
    public const MODEL_SONNET_OLD    = 'claude-3-5-sonnet-20241022';

    /** Claude Opus 4.6 via AWS Bedrock EU — Mythos-class, elite tier (≥€150 wallet). */
    public const MODEL_OPUS_46_BEDROCK = 'bedrock/eu.anthropic.claude-opus-4-6-v1';

    /** Claude Sonnet 4.5 via AWS Bedrock EU — standaard elite code tasks. */
    public const MODEL_SONNET_45_BEDROCK = 'bedrock/eu.anthropic.claude-sonnet-4-5-20250929-v1:0';

    /** @deprecated Gebruik MODEL_OPUS_46_BEDROCK */
    public const MODEL_SONNET_4_BEDROCK = 'bedrock/eu.anthropic.claude-sonnet-4-5-20250929-v1:0';

    /** Google Gemini — Deep History & Social Search (zie LlmClient gemini/* branch). */
    public const MODEL_GEMINI_DEFAULT = 'gemini/gemini-1.5-pro';

    public const PURPOSE_DEEP_HISTORY_AUDIT       = 'deep_history_audit';
    public const PURPOSE_SOCIAL_SEARCH_ARBITRAGE  = 'social_search_arbitrage';

    /** Task complexity — bepaalt model-tier onafhankelijk van wallet-grootte. */
    public const COMPLEXITY_SIMPLE       = 'simple';        // sentiment, ping, quick check
    public const COMPLEXITY_STANDARD     = 'standard';      // validatie, buy-signal
    public const COMPLEXITY_COMPLEX      = 'complex';        // deep analysis, iteratieve refactor
    public const COMPLEXITY_ARCHITECTURE = 'architecture';   // arch-beslissingen → altijd Elite
    public const COMPLEXITY_SECURITY     = 'security';       // security audit → altijd Elite
    public const COMPLEXITY_AUDIT        = 'audit';          // weekly audit → altijd Elite + 20k thinking

    /** Volatiliteit-drempels (dagelijkse prijsbeweging als ratio, bijv. 0.035 = 3.5%). */
    private const VOLATILITY_HIGH    = 0.035;
    private const VOLATILITY_EXTREME = 0.06;

    private const TIER_PROFESSIONAL_EUR  = 50.0;
    private const TIER_STANDARD_EUR      = 100.0;
    private const TIER_ELITE_EUR         = 150.0;
    private const MAX_SONNET_DAILY_RATIO = 0.20;

    /** Dagelijks kostenplafond voor Elite (Opus 4.6) — voorkomt runaway thinking. */
    private const MAX_ELITE_DAILY_EUR    = 2.50;

    /** TTL voor gecachede volatiliteitswaarden in seconden. */
    private const VOLATILITY_CACHE_TTL   = 60;

    /** In-memory cache als APCu niet beschikbaar is: ['symbol' => [value, expires_at]] */
    private static array $volatilityCache = [];

    private string $ledgerPath;

    public function __construct(?string $basePath = null)
    {
        $base             = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $this->ledgerPath = $base . '/data/evolution/trading/model_cost_ledger.json';
    }

    /**
     * Select model met Volatility Trigger + Complexity Guard.
     *
     * Elite (Opus 4.6) wordt ALLEEN ingezet bij:
     *   - taskComplexity = architecture / security / audit, OF
     *   - marketVolatility ≥ 6% (extreme) + wallet ≥ €150
     * Bij business-as-usual blijft Premium (Sonnet 3.7) de standaard.
     *
     * @param float  $tradingEur       Huidige trading wallet in EUR
     * @param string $complexity       Zie COMPLEXITY_* constanten
     * @param float  $marketVolatility Dagelijkse prijsbeweging als ratio (bijv. 0.04 = 4%)
     */
    public function selectModelForTask(
        float  $tradingEur,
        string $complexity       = self::COMPLEXITY_STANDARD,
        float  $marketVolatility = 0.0
    ): string {
        // Gemini-purposes gaan altijd naar Gemini
        if ($complexity === self::PURPOSE_DEEP_HISTORY_AUDIT ||
            $complexity === self::PURPOSE_SOCIAL_SEARCH_ARBITRAGE) {
            $m = trim((string)(getenv('GEMINI_MODEL') ?: ''));
            return ($m !== '') ? (str_starts_with($m, 'gemini/') ? $m : 'gemini/' . $m) : self::MODEL_GEMINI_DEFAULT;
        }

        // Economy modus: wallet onder €50 → alleen Haiku
        if ($tradingEur < self::TIER_PROFESSIONAL_EUR) {
            return self::MODEL_HAIKU_45;
        }

        // Elite-taken vereisen Opus 4.6 — ongeacht marktvolatiliteit
        $alwaysElite = [self::COMPLEXITY_ARCHITECTURE, self::COMPLEXITY_SECURITY, self::COMPLEXITY_AUDIT];
        if ($tradingEur >= self::TIER_ELITE_EUR && in_array($complexity, $alwaysElite, true)) {
            return $this->eliteBudgetAvailable() ? self::MODEL_OPUS_46_BEDROCK : self::MODEL_SONNET_45_BEDROCK;
        }

        // Extreme volatiliteit (≥6%) + Elite wallet: Opus 4.6
        if ($tradingEur >= self::TIER_ELITE_EUR &&
            $marketVolatility >= self::VOLATILITY_EXTREME &&
            $complexity === self::COMPLEXITY_COMPLEX) {
            return $this->eliteBudgetAvailable() ? self::MODEL_OPUS_46_BEDROCK : self::MODEL_SONNET_45_BEDROCK;
        }

        // Hoge volatiliteit (≥3.5%) + €100+ wallet: Sonnet 3.7 (premium, met thinking)
        if ($tradingEur >= self::TIER_STANDARD_EUR &&
            ($marketVolatility >= self::VOLATILITY_HIGH || $complexity === self::COMPLEXITY_COMPLEX)) {
            return self::MODEL_SONNET;
        }

        // €100+ standaard validatie: Sonnet 3.7
        if ($tradingEur >= self::TIER_STANDARD_EUR) {
            return self::MODEL_SONNET;
        }

        // €50–€99: Sonnet indien dagbudget beschikbaar, anders Haiku
        return $this->sonnetBudgetAvailable() ? self::MODEL_SONNET : self::MODEL_HAIKU_45;
    }

    /**
     * Geeft het optimale thinking budget in tokens op basis van taak-type.
     * Voorkomt runaway thinking bij eenvoudige taken.
     *
     * @return int Thinking budget in tokens (0 = geen extended thinking)
     */
    public static function getThinkingBudget(string $complexity): int
    {
        return match($complexity) {
            self::COMPLEXITY_AUDIT        => 20000,  // Weekly audit: maximale diepte
            self::COMPLEXITY_ARCHITECTURE => 12000,  // Architectuur: grondig
            self::COMPLEXITY_SECURITY     => 12000,  // Security: grondig
            self::COMPLEXITY_COMPLEX      => 8000,   // Complex maar beperkt
            self::COMPLEXITY_STANDARD     => 4000,   // Standaard: snel
            default                       => 0,       // Simple: geen thinking
        };
    }

    /**
     * @deprecated Gebruik selectModelForTask() met COMPLEXITY_* constanten.
     * Behouden voor backwards compatibility.
     */
    public function selectModel(float $tradingEur, string $purpose = 'validation'): string
    {
        $complexity = match($purpose) {
            'deep_analysis'                        => self::COMPLEXITY_COMPLEX,
            self::PURPOSE_DEEP_HISTORY_AUDIT,
            self::PURPOSE_SOCIAL_SEARCH_ARBITRAGE  => $purpose,
            default                                => self::COMPLEXITY_STANDARD,
        };
        return $this->selectModelForTask($tradingEur, $complexity);
    }

    /**
     * Record API cost for today's budget ledger.
     */
    public function recordCost(string $model, float $costEur): void
    {
        $ledger = $this->loadLedger();
        $today  = date('Y-m-d');

        if (!isset($ledger[$today])) {
            $ledger[$today] = ['haiku' => 0.0, 'sonnet' => 0.0, 'elite' => 0.0, 'gemini' => 0.0, 'total' => 0.0];
        }
        if (!isset($ledger[$today]['gemini'])) {
            $ledger[$today]['gemini'] = 0.0;
        }
        if (!isset($ledger[$today]['elite'])) {
            $ledger[$today]['elite'] = 0.0;
        }

        if (str_contains($model, 'haiku')) {
            $ledger[$today]['haiku'] = round((float)$ledger[$today]['haiku'] + $costEur, 6);
        } elseif (str_contains(strtolower($model), 'gemini')) {
            $ledger[$today]['gemini'] = round((float)$ledger[$today]['gemini'] + $costEur, 6);
        } elseif (str_contains($model, 'opus')) {
            $ledger[$today]['elite'] = round((float)$ledger[$today]['elite'] + $costEur, 6);
        } else {
            $ledger[$today]['sonnet'] = round((float)$ledger[$today]['sonnet'] + $costEur, 6);
        }
        $ledger[$today]['total'] = round(
            (float)$ledger[$today]['haiku'] +
            (float)$ledger[$today]['sonnet'] +
            (float)$ledger[$today]['elite'] +
            (float)$ledger[$today]['gemini'],
            6
        );

        // Keep only last 7 days
        krsort($ledger);
        $ledger = array_slice($ledger, 0, 7, true);

        $dir = dirname($this->ledgerPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($this->ledgerPath, json_encode($ledger, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Today's cost breakdown.
     *
     * @return array{haiku: float, sonnet: float, elite: float, gemini: float, total: float, sonnet_ratio: float, elite_eur: float}
     */
    public function todayCosts(): array
    {
        $ledger = $this->loadLedger();
        $today  = date('Y-m-d');
        $day    = $ledger[$today] ?? ['haiku' => 0.0, 'sonnet' => 0.0, 'elite' => 0.0, 'gemini' => 0.0, 'total' => 0.0];
        $total  = (float)$day['total'];

        return [
            'haiku'        => (float)$day['haiku'],
            'sonnet'       => (float)$day['sonnet'],
            'elite'        => (float)($day['elite'] ?? 0.0),
            'gemini'       => (float)($day['gemini'] ?? 0.0),
            'total'        => $total,
            'sonnet_ratio' => $total > 0.001 ? round((float)$day['sonnet'] / $total, 3) : 0.0,
            'elite_eur'    => (float)($day['elite'] ?? 0.0),
        ];
    }

    /** Returns true when Sonnet can still be used today (ratio < 20%). */
    private function sonnetBudgetAvailable(): bool
    {
        $costs = $this->todayCosts();
        if ($costs['total'] < 0.001) {
            return true;
        }
        return $costs['sonnet_ratio'] < self::MAX_SONNET_DAILY_RATIO;
    }

    /**
     * Sla een volatiliteitswaarde op in APCu (bij voorkeur) of statische array.
     * Roep dit aan vanuit je markt-datafeed, niet bij elke model-selectie.
     *
     * @param string $symbol      Bijv. 'BTC', 'ETH', 'global'
     * @param float  $volatility  Dagelijkse prijsbeweging als ratio (bijv. 0.04 = 4%)
     * @param int    $ttl         Cache-duur in seconden (standaard 60)
     */
    public function cacheVolatility(string $symbol, float $volatility, int $ttl = self::VOLATILITY_CACHE_TTL): void
    {
        $key = 'modsel_vol_' . $symbol;
        if (function_exists('apcu_store')) {
            \apcu_store($key, $volatility, $ttl);
        }
        // Altijd ook in statische array — fallback + nul-latentie binnen hetzelfde process
        self::$volatilityCache[$symbol] = [$volatility, time() + $ttl];
    }

    /**
     * Lees gecachede volatiliteit (APCu → static → null).
     * Null = onbekend/verlopen → gebruik 0.0 als veilige default.
     */
    public function getCachedVolatility(string $symbol): ?float
    {
        $key = 'modsel_vol_' . $symbol;
        if (function_exists('apcu_fetch')) {
            $success = false;
            $val = \apcu_fetch($key, $success);
            if ($success) {
                return (float)$val;
            }
        }
        $entry = self::$volatilityCache[$symbol] ?? null;
        if ($entry !== null && $entry[1] >= time()) {
            return (float)$entry[0];
        }
        return null;
    }

    /**
     * Returns true when Elite (Opus 4.6) dagplafond nog niet bereikt is.
     * Leest eerst het realtime JSONL-bestand van BedrockProvider (geen CloudWatch-lag),
     * valt terug op het JSON-ledger als de JSONL-log niet beschikbaar is.
     * Voorkomt runaway thinking: max €2,50/dag aan Opus-tokens.
     */
    private function eliteBudgetAvailable(): bool
    {
        $key = 'bedrock_elite_spent_' . date('Y-m-d');

        // Laag 1: APCu — O(1), gedeeld tussen alle PHP-workers, bijgewerkt door BedrockProvider
        if (function_exists('apcu_fetch')) {
            $success  = false;
            $apcuSpent = \apcu_fetch($key, $success);
            if ($success) {
                return (float)$apcuSpent < self::MAX_ELITE_DAILY_EUR;
            }
        }

        // Laag 2: JSONL-log — exacte som, maar I/O (alleen als APCu cold/leeg)
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4);
        $jsonl    = $basePath . '/data/logs/bedrock_cost_' . date('Y-m-d') . '.jsonl';
        if (is_file($jsonl)) {
            $opusEur = 0.0;
            $fh = @fopen($jsonl, 'r');
            if ($fh !== false) {
                while (($line = fgets($fh)) !== false) {
                    $row = json_decode(trim($line), true);
                    if (is_array($row) && str_contains((string)($row['model'] ?? ''), 'opus')) {
                        $opusEur += (float)($row['cost_eur'] ?? 0.0);
                    }
                }
                fclose($fh);
            }
            // Populeer APCu met de berekende som voor volgende calls
            if (function_exists('apcu_store')) {
                \apcu_store($key, round($opusEur, 6), 86400);
            }
            return $opusEur < self::MAX_ELITE_DAILY_EUR;
        }

        // Laag 3: JSON-ledger (trading bot schrijft dit) — meest conservatief
        return $this->todayCosts()['elite_eur'] < self::MAX_ELITE_DAILY_EUR;
    }

    private function loadLedger(): array
    {
        if (!is_file($this->ledgerPath)) {
            return [];
        }
        return json_decode((string)file_get_contents($this->ledgerPath), true) ?? [];
    }
}
