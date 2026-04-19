<?php

declare(strict_types=1);

namespace App\Core\Evolution\Intelligence;

/**
 * MythosProxyService — Groq Llama als "Local Mythos Proxy".
 *
 * Drie technieken om Llama-intelligentie naar Mythos-niveau te tillen:
 *
 * 1. INTERNAL MONOLOGUE
 *    Llama wordt via prompt engineering gedwongen eerst een [INTERNAL_MONOLOGUE]
 *    te schrijven vóór het antwoord. Dit simuleert extended thinking op
 *    Groq-snelheid (300+ TPS) in plaats van Opus 4.6-kosten.
 *
 * 2. ADVERSARIAL BRAIN
 *    brain_mythos_security.json / brain_mythos_trading.json bevatten curated
 *    exploit-patronen en logic-flaws. Llama "leent" de kennis van eerdere audits.
 *    Brain-bestanden worden geparsed klaargehouden in APCu (zero I/O overhead).
 *
 * 3. CROSS-CHECK (Adversarial Skill)
 *    Llama valt de output van een ander model (Sonnet/Opus) aan:
 *    "Zoek 3 redenen waarom deze beslissing fout is onder extreme condities."
 *    Bootst de adversarial stijl van Mythos na zonder de kosten.
 *
 * @example — Security audit
 *   $proxy = new MythosProxyService($groqKey, $basePath);
 *   $result = $proxy->reason('Audit de auth-flow in AuthController.php', 'security');
 *   // result['monologue'] = intern redeneerproces van Llama
 *   // result['answer']    = definitieve bevinding
 *
 * @example — Cross-check Sonnet output
 *   $attack = $proxy->crossCheck($sonnetOutput, 'buy BTC at $94k', ['volatility' => 0.08]);
 *   // attack['attacks'] = ['Reden 1: ...', 'Reden 2: ...', 'Reden 3: ...']
 *   // attack['verdict'] = 'HOLD' | 'PROCEED' | 'ABORT'
 */
final class MythosProxyService
{
    private const GROQ_URL     = 'https://api.groq.com/openai/v1/chat/completions';
    private const GROQ_MODEL   = 'llama-3.1-8b-instant';
    private const BRAIN_CACHE_TTL = 3600; // 1u — brain-bestanden veranderen zelden
    private const CURL_TIMEOUT    = 12;

    private string $basePath;

    /** @var array<string, array> statische fallback als APCu niet beschikbaar */
    private static array $brainCache = [];

    /** @var array<string, bool> track welke brains geladen zijn voor health-check */
    private static array $brainLoaded = [];

    public function __construct(
        private readonly string $groqApiKey,
        ?string $basePath = null
    ) {
        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
    }

    /**
     * Controleer of een brain-bestand correct geladen is en beschikbaar in APCu.
     * Gebruik dit vóór kritieke beslissingen op piekmoment.
     *
     * @return array{healthy: bool, source: string, warning: string}
     */
    public function brainHealth(string $name): array
    {
        $cacheKey = 'mythos_brain_' . $name;

        // Laag 1: APCu beschikbaar + key aanwezig?
        if (function_exists('apcu_fetch')) {
            $success = false;
            $val     = \apcu_fetch($cacheKey, $success);
            if ($success && is_array($val) && !empty($val)) {
                return ['healthy' => true, 'source' => 'apcu', 'warning' => ''];
            }
        }

        // Laag 2: static cache
        if (!empty(self::$brainCache[$name])) {
            return ['healthy' => true, 'source' => 'static', 'warning' => 'APCu miss — static cache actief'];
        }

        // Laag 3: schijf bereikbaar?
        $file = $this->basePath . '/config/brain_mythos_' . $name . '.json';
        if (is_file($file)) {
            // Warm de caches opnieuw op
            $this->loadBrain($name);
            return ['healthy' => true, 'source' => 'disk_reload', 'warning' => "APCu koud — brain '{$name}' herladen van schijf"];
        }

        // Brain volledig ontbreekt — degraded mode
        $warning = "BRAIN DEGRADED: brain_mythos_{$name}.json niet gevonden én APCu leeg. "
                 . "Redeneren zonder domein-kennis — overweeg Haiku als fallback.";
        error_log('[MythosProxy] ' . $warning);

        return ['healthy' => false, 'source' => 'none', 'warning' => $warning];
    }

    // ─── Publieke interface ────────────────────────────────────────────────────

    /**
     * Geforceerd redeneren met Internal Monologue + Brain-injectie.
     *
     * Llama schrijft eerst een [INTERNAL_MONOLOGUE] — stap-voor-stap redeneren —
     * vóór het definitieve antwoord. Brain-bestand verrijkt de context met
     * domein-specifieke patronen.
     *
     * @param string $task       Taakbeschrijving voor Llama
     * @param string $brainName  'security' | 'trading' | '' (geen brain)
     * @param string $extra      Extra context (code snippet, log excerpt, etc.)
     *
     * @return array{monologue: string, answer: string, model: string, latency_ms: float, cached_brain: bool}
     */
    public function reason(string $task, string $brainName = 'security', string $extra = ''): array
    {
        [$brain, $brainCached] = $this->loadBrain($brainName);
        $systemPrompt          = $this->buildReasoningSystem($brain, $brainName);
        $userPrompt            = $this->buildReasoningUser($task, $extra);

        $start  = microtime(true);
        $raw    = $this->callGroq($systemPrompt, $userPrompt, 1200);
        $latency = round((microtime(true) - $start) * 1000, 1);

        [$monologue, $answer] = $this->parseMonologue($raw);

        return [
            'monologue'    => $monologue,
            'answer'       => $answer,
            'model'        => self::GROQ_MODEL,
            'latency_ms'   => $latency,
            'cached_brain' => $brainCached,
        ];
    }

    /**
     * Adversarial Cross-Check — Llama valt de output van een ander model aan.
     *
     * Taak: "Zoek 3 redenen waarom deze beslissing fout is."
     * Bootst Mythos' adversarial review na; ideaal als tweede mening na Sonnet/Opus.
     *
     * @param string $modelOutput  Volledige output van het te aanvallen model
     * @param string $decision     De kernbeslissing (bijv. "buy BTC", "deploy to prod")
     * @param array  $context      Extra context: ['volatility' => 0.08, 'domain' => 'trading']
     *
     * @return array{attacks: string[], verdict: string, confidence: float, latency_ms: float}
     */
    public function crossCheck(string $modelOutput, string $decision, array $context = []): array
    {
        $domain    = (string)($context['domain'] ?? 'general');
        [$brain]   = $this->loadBrain($domain === 'general' ? '' : $domain);
        $systemPrompt = $this->buildCrossCheckSystem($brain, $context);
        $userPrompt   = $this->buildCrossCheckUser($modelOutput, $decision);

        $start   = microtime(true);
        $raw     = $this->callGroq($systemPrompt, $userPrompt, 800);
        $latency = round((microtime(true) - $start) * 1000, 1);

        return array_merge(
            $this->parseCrossCheck($raw),
            ['latency_ms' => $latency]
        );
    }

    /**
     * Laad brain-bestand: APCu → static cache → schijf.
     * Pre-parsed JSON — nul overhead bij warme cache.
     *
     * @return array{0: array, 1: bool}  [brainData, wasCached]
     */
    public function loadBrain(string $name): array
    {
        if ($name === '') {
            return [[], false];
        }

        $cacheKey = 'mythos_brain_' . $name;

        // Laag 1: APCu (gedeeld tussen workers, 1u TTL)
        if (function_exists('apcu_fetch')) {
            $success = false;
            $cached  = \apcu_fetch($cacheKey, $success);
            if ($success && is_array($cached)) {
                return [$cached, true];
            }
        }

        // Laag 2: static array (zelfde process, zelfde request)
        if (isset(self::$brainCache[$name])) {
            return [self::$brainCache[$name], true];
        }

        // Laag 3: schijf (cold load)
        $file = $this->basePath . '/config/brain_mythos_' . $name . '.json';
        if (!is_file($file)) {
            return [[], false];
        }

        $data = json_decode((string)file_get_contents($file), true) ?? [];

        // Populeer beide caches
        self::$brainCache[$name] = $data;
        if (function_exists('apcu_store')) {
            \apcu_store($cacheKey, $data, self::BRAIN_CACHE_TTL);
        }

        return [$data, false];
    }

    // ─── Prompt builders ──────────────────────────────────────────────────────

    private function buildReasoningSystem(array $brain, string $domain): string
    {
        $brainContext = '';
        if (!empty($brain)) {
            $mindset = implode("\n- ", (array)($brain['audit_mindset'] ?? []));

            $patterns = '';
            $patternKey = $domain === 'trading' ? 'manipulation_patterns' : 'php_framework_exploits';
            foreach ((array)($brain[$patternKey] ?? []) as $p) {
                if (!is_array($p)) continue;
                $patterns .= "\n• [{$p['id']}] {$p['pattern']} — risico: {$p['risk']}";
            }

            $adversarial = implode("\n- ", (array)($brain['adversarial_questions'] ?? []));

            $brainContext = <<<BRAIN

=== DOMEIN-KENNIS ({$domain}) ===
DENKWIJZE:
- {$mindset}

BEKENDE PATRONEN:{$patterns}

ADVERSARIALE VRAGEN (stel jezelf deze):
- {$adversarial}
BRAIN;
        }

        return <<<SYS
Je bent een elite technische analyst die ALTIJD grondig nadenkt vóór je antwoordt.

VEREISTE STRUCTUUR — wijk hier NOOIT van af:

[INTERNAL_MONOLOGUE]
<schrijf hier je stap-voor-stap redeneerproces; identificeer aannames, edge-cases,
 tegenstrijdigheden en risico's; wees kritisch en adversariaal>
[/INTERNAL_MONOLOGUE]

[ANSWER]
<geef hier alleen je definitieve, concrete bevinding of aanbeveling; geen herhaling van de monoloog>
[/ANSWER]
{$brainContext}
SYS;
    }

    private function buildReasoningUser(string $task, string $extra): string
    {
        $extraSection = $extra !== '' ? "\n\nCONTEXT/CODE:\n{$extra}" : '';
        return "TAAK: {$task}{$extraSection}";
    }

    private function buildCrossCheckSystem(array $brain, array $context): string
    {
        $vol = isset($context['volatility'])
            ? 'Marktvolatiliteit: ' . round((float)$context['volatility'] * 100, 1) . '%'
            : '';

        $adversarialQuestions = '';
        if (!empty($brain['adversarial_questions'])) {
            $adversarialQuestions = "\n\nADVERSARIALE VRAGEN OM TE STELLEN:\n- "
                . implode("\n- ", (array)$brain['adversarial_questions']);
        }

        return <<<SYS
Je bent een adversarial reviewer. Je taak is om een beslissing van een ander AI-model
aan te vallen en te vernietigen. Je MOET 3 concrete redenen vinden waarom de beslissing
fout zou zijn — ook als die beslissing op het eerste gezicht correct lijkt.

Denk als een sceptische senior engineer die de beslissing moet verdedigen tegenover:
- Een hostile board meeting
- Een regulatoire audit
- Een black-swan marktgebeurtenis{$adversarialQuestions}

{$vol}

Geef je output ALTIJD in dit exacte JSON-formaat (geen markdown):
{
  "attacks": [
    "Reden 1: ...",
    "Reden 2: ...",
    "Reden 3: ..."
  ],
  "verdict": "ABORT|PROCEED|HOLD",
  "confidence": 0.0-1.0,
  "critical_risk": "één zin over het grootste risico"
}
SYS;
    }

    private function buildCrossCheckUser(string $modelOutput, string $decision): string
    {
        $truncated = strlen($modelOutput) > 2000
            ? substr($modelOutput, 0, 2000) . '... [truncated]'
            : $modelOutput;

        return "BESLISSING TE AANVALLEN: {$decision}\n\nORIGINELE MODEL-OUTPUT:\n{$truncated}";
    }

    // ─── Response parsers ─────────────────────────────────────────────────────

    /** @return array{0: string, 1: string} [monologue, answer] */
    private function parseMonologue(string $raw): array
    {
        $monologue = '';
        $answer    = $raw;

        if (preg_match('/\[INTERNAL_MONOLOGUE\](.*?)\[\/INTERNAL_MONOLOGUE\]/s', $raw, $m)) {
            $monologue = trim($m[1]);
        }
        if (preg_match('/\[ANSWER\](.*?)\[\/ANSWER\]/s', $raw, $m)) {
            $answer = trim($m[1]);
        } elseif ($monologue !== '') {
            // Als [ANSWER] ontbreekt maar [INTERNAL_MONOLOGUE] aanwezig: gebruik rest van tekst
            $answer = trim(preg_replace('/\[INTERNAL_MONOLOGUE\].*?\[\/INTERNAL_MONOLOGUE\]/s', '', $raw) ?? $raw);
        }

        return [$monologue, $answer];
    }

    /** @return array{attacks: string[], verdict: string, confidence: float, critical_risk: string} */
    private function parseCrossCheck(string $raw): array
    {
        $default = [
            'attacks'       => ['Cross-check parsing mislukt — oorspronkelijke beslissing onzeker'],
            'verdict'       => 'HOLD',
            'confidence'    => 0.5,
            'critical_risk' => 'Llama-respons niet parseerbaar',
        ];

        // Strip markdown code fences
        $cleaned = preg_replace('/^```[a-z]*\n?|```$/m', '', $raw) ?? $raw;
        $cleaned = trim($cleaned);

        $data = json_decode($cleaned, true);
        if (!is_array($data)) {
            // Probeer JSON te extraheren uit tekst
            if (preg_match('/\{.*\}/s', $cleaned, $m)) {
                $data = json_decode($m[0], true);
            }
        }

        if (!is_array($data)) {
            return $default;
        }

        $attacks = array_values(array_filter(
            (array)($data['attacks'] ?? []),
            fn($a) => is_string($a) && strlen($a) > 5
        ));

        $verdict = strtoupper((string)($data['verdict'] ?? 'HOLD'));
        if (!in_array($verdict, ['ABORT', 'PROCEED', 'HOLD'], true)) {
            $verdict = 'HOLD';
        }

        return [
            'attacks'       => $attacks ?: $default['attacks'],
            'verdict'       => $verdict,
            'confidence'    => min(1.0, max(0.0, (float)($data['confidence'] ?? 0.5))),
            'critical_risk' => (string)($data['critical_risk'] ?? ''),
        ];
    }

    // ─── HTTP ─────────────────────────────────────────────────────────────────

    private function callGroq(string $system, string $user, int $maxTokens = 800): string
    {
        if ($this->groqApiKey === '') {
            return '[ANSWER]Geen Groq API-key geconfigureerd.[/ANSWER]';
        }

        $body = json_encode([
            'model'       => self::GROQ_MODEL,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'max_tokens'  => $maxTokens,
            'temperature' => 0.1,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init(self::GROQ_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->groqApiKey,
            ],
        ]);
        $raw     = (string)curl_exec($ch);
        $curlErr = curl_errno($ch);
        curl_close($ch);

        if ($curlErr !== 0) {
            return '[ANSWER]Groq niet bereikbaar (curl error ' . $curlErr . ').[/ANSWER]';
        }

        $data = json_decode($raw, true) ?: [];
        return trim((string)($data['choices'][0]['message']['content'] ?? ''));
    }
}
