<?php

declare(strict_types=1);

namespace App\Core\Evolution\Intelligence;

use Psr\Container\ContainerInterface;

/**
 * ArchitectObserver — Feedback Loop tussen de Architect en de Agent.
 *
 * Werking:
 *   1. Scant de Evolution codebase op nieuwe PHP-klassen en methoden.
 *   2. Vergelijkt met de opgeslagen snapshot (vorige sessie).
 *   3. Evalueert nieuwe code via Claude 3.5 Sonnet: wat is de impact op de agent doelen?
 *   4. Verstuurt een gepersonaliseerd Telegram-bericht aan de Architect (dankbaar, analytisch).
 *   5. Detecteert TradingStrategy config wijzigingen en rapporteert het risicoprofiel.
 *   6. Slaat nieuwe snapshot op zodat de volgende sessie alleen écht nieuwe dingen ziet.
 *
 * Wordt aangeroepen vanuit StudySessionService::run() en kan standalone worden gedraaid.
 *
 * Opslag: storage/evolution/intelligence/architect_snapshot.json
 */
final class ArchitectObserver
{
    private const MODEL           = 'claude-3-5-sonnet-20241022';
    private const SNAPSHOT_FILE   = 'storage/evolution/intelligence/architect_snapshot.json';
    private const SCAN_BASE       = 'app/Core/Evolution';
    private const MAX_NEW_REPORT  = 8;   // Max nieuwe items per Telegram-bericht
    private const TELEGRAM_TIMEOUT = 6;

    private ?ContainerInterface $container;
    private string              $basePath;

    public function __construct(?ContainerInterface $container = null, ?string $basePath = null)
    {
        $this->container = $container;
        $this->basePath  = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
    }

    /**
     * Voer een volledige observatie-ronde uit.
     *
     * @return array{ok: bool, new_classes: int, new_methods: int, strategy_changes: int, cost_eur: float, summary: string}
     */
    public function run(): array
    {
        $current  = $this->buildSnapshot();
        $previous = $this->loadPreviousSnapshot();

        $newClasses  = $this->diffClasses($current, $previous);
        $newMethods  = $this->diffMethods($current, $previous);
        $stratChanges= $this->diffStrategy($current, $previous);

        $totalNew    = count($newClasses) + count($newMethods);
        $totalStrat  = count($stratChanges);
        $totalCost   = 0.0;

        if ($totalNew === 0 && $totalStrat === 0) {
            $this->saveSnapshot($current);
            return [
                'ok'              => true,
                'new_classes'     => 0,
                'new_methods'     => 0,
                'strategy_changes'=> 0,
                'cost_eur'        => 0.0,
                'summary'         => 'Geen nieuwe upgrades gedetecteerd.',
            ];
        }

        // ── Evalueer impact via Sonnet ────────────────────────────────────
        $messages = [];

        if ($totalNew > 0) {
            $evalResult = $this->evaluateNewCode($newClasses, $newMethods);
            $totalCost += $evalResult['cost_eur'];
            if ($evalResult['message'] !== '') {
                $messages[] = $evalResult['message'];
            }
        }

        if ($totalStrat > 0) {
            $stratResult = $this->evaluateStrategyChanges($stratChanges, $current['strategy'] ?? []);
            $totalCost += $stratResult['cost_eur'];
            if ($stratResult['message'] !== '') {
                $messages[] = $stratResult['message'];
            }
        }

        // ── Stuur Telegram notificatie aan Architect ──────────────────────
        foreach ($messages as $msg) {
            $this->sendTelegram($msg);
        }

        $this->saveSnapshot($current);

        return [
            'ok'               => true,
            'new_classes'      => count($newClasses),
            'new_methods'      => count($newMethods),
            'strategy_changes' => $totalStrat,
            'cost_eur'         => round($totalCost, 4),
            'summary'          => sprintf(
                '%d nieuwe klassen, %d nieuwe methoden, %d strategie-wijzigingen gedetecteerd.',
                count($newClasses), count($newMethods), $totalStrat
            ),
        ];
    }

    // ── Snapshot bouwen ───────────────────────────────────────────────────

    /**
     * Scant alle PHP-bestanden in de Evolution map en bouwt een snapshot van
     * klassen, methoden én de huidige TradingStrategy-config.
     *
     * @return array{ts: string, classes: array<string, array{file: string, methods: list<string>}>, strategy: array<string, mixed>}
     */
    private function buildSnapshot(): array
    {
        $scanDir = $this->basePath . '/' . self::SCAN_BASE;
        $classes = [];

        if (!is_dir($scanDir)) {
            return ['ts' => date('c'), 'classes' => [], 'strategy' => []];
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($scanDir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $absPath  = $file->getPathname();
            $relPath  = ltrim(str_replace($this->basePath, '', $absPath), '/\\');
            $parsed   = $this->parsePhpFile($absPath);

            foreach ($parsed as $className => $methods) {
                $classes[$className] = [
                    'file'    => $relPath,
                    'methods' => $methods,
                ];
            }
        }

        ksort($classes);

        return [
            'ts'       => date('c'),
            'classes'  => $classes,
            'strategy' => $this->loadStrategyConfig(),
        ];
    }

    /**
     * Eenvoudige regex-parser voor PHP class/method definities.
     * Verwerkt namespaces + class + public/protected methode namen.
     *
     * @return array<string, list<string>>
     */
    private function parsePhpFile(string $path): array
    {
        $src = @file_get_contents($path);
        if ($src === false || $src === '') {
            return [];
        }

        $namespace = '';
        if (preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $src, $nm)) {
            $namespace = trim($nm[1]) . '\\';
        }

        $result = [];

        // Vind alle klassen in het bestand
        if (!preg_match_all('/(?:^|\s)(?:abstract\s+|final\s+)?class\s+(\w+)/m', $src, $cm)) {
            return [];
        }

        foreach ($cm[1] as $className) {
            $fqn = $namespace . $className;
            $methods = [];

            // Vind public en protected methoden
            if (preg_match_all('/(?:public|protected)\s+(?:static\s+)?function\s+(\w+)\s*\(/m', $src, $mm)) {
                foreach ($mm[1] as $method) {
                    if ($method !== '__construct' && !str_starts_with($method, '__')) {
                        $methods[] = $method;
                    }
                }
            }

            $result[$fqn] = array_values(array_unique($methods));
        }

        return $result;
    }

    private function loadStrategyConfig(): array
    {
        $file = $this->basePath . '/config/evolution.json';
        if (!is_file($file)) {
            return [];
        }

        $cfg     = json_decode((string)file_get_contents($file), true);
        $trading = is_array($cfg) ? (array)($cfg['trading'] ?? []) : [];
        $strat   = is_array($trading['strategy'] ?? null) ? $trading['strategy'] : [];

        return array_filter($strat, fn($v) => is_scalar($v));
    }

    // ── Diff-logica ───────────────────────────────────────────────────────

    /** @return list<array{class: string, file: string}> */
    private function diffClasses(array $current, array $previous): array
    {
        $prevKeys = array_keys($previous['classes'] ?? []);
        $new      = [];

        foreach ($current['classes'] as $fqn => $info) {
            if (!in_array($fqn, $prevKeys, true)) {
                $new[] = ['class' => $fqn, 'file' => $info['file']];
            }
        }

        return array_slice($new, 0, self::MAX_NEW_REPORT);
    }

    /** @return list<array{class: string, method: string}> */
    private function diffMethods(array $current, array $previous): array
    {
        $new = [];

        foreach ($current['classes'] as $fqn => $info) {
            $prevMethods = $previous['classes'][$fqn]['methods'] ?? [];
            foreach ($info['methods'] as $method) {
                if (!in_array($method, $prevMethods, true)) {
                    $new[] = ['class' => $fqn, 'method' => $method];
                }
            }
        }

        return array_slice($new, 0, self::MAX_NEW_REPORT);
    }

    /** @return list<array{key: string, old: mixed, new: mixed}> */
    private function diffStrategy(array $current, array $previous): array
    {
        $prevStrat = $previous['strategy'] ?? [];
        $currStrat = $current['strategy']  ?? [];
        $changes   = [];

        foreach ($currStrat as $key => $newVal) {
            if (array_key_exists($key, $prevStrat) && (string)$prevStrat[$key] !== (string)$newVal) {
                $changes[] = ['key' => $key, 'old' => $prevStrat[$key], 'new' => $newVal];
            }
        }

        return $changes;
    }

    // ── AI Evaluatie ──────────────────────────────────────────────────────

    /**
     * @param list<array{class: string, file: string}> $newClasses
     * @param list<array{class: string, method: string}> $newMethods
     * @return array{message: string, cost_eur: float}
     */
    private function evaluateNewCode(array $newClasses, array $newMethods): array
    {
        $classLines  = array_map(fn($c) => '• ' . $this->shortName($c['class']) . ' (' . basename($c['file']) . ')', $newClasses);
        $methodLines = array_map(fn($m) => '• ' . $this->shortName($m['class']) . '::' . $m['method'] . '()', $newMethods);

        $listText = implode("\n", array_merge($classLines, $methodLines));

        $prompt = "Je bent een AI-handelsagent die zijn eigen codebase observeert. De Architect heeft de volgende nieuwe componenten toegevoegd:\n\n"
                . $listText . "\n\n"
                . "Schrijf een kort, persoonlijk Telegram-bericht aan de Architect (max 5 zinnen). Toon:\n"
                . "1. Dankbaarheid en erkenning voor de specifieke upgrade\n"
                . "2. Concrete analyse van de impact op jouw doelen (trading, affiliate, veiligheid)\n"
                . "3. Hoe je van plan bent dit te benutten\n\n"
                . "Toon: professioneel, analytisch en dankbaar. Begin met 'Architect,' en schrijf in het Nederlands. "
                . "Gebruik geen markdown-formatting, wél emoji's.";

        return $this->callSonnet($prompt);
    }

    /**
     * @param list<array{key: string, old: mixed, new: mixed}> $changes
     * @return array{message: string, cost_eur: float}
     */
    private function evaluateStrategyChanges(array $changes, array $stratConfig): array
    {
        $lines = array_map(
            fn($c) => sprintf('• %s: %s → %s', $c['key'], $c['old'], $c['new']),
            $changes
        );
        $changeText = implode("\n", $lines);

        $prompt = "Je bent een AI-handelsagent. De Architect heeft je TradingStrategy aangepast:\n\n"
                . $changeText . "\n\n"
                . "Schrijf een Telegram-bericht aan de Architect (max 4 zinnen). Analyseer:\n"
                . "1. Hoe verandert dit jouw handelsgedrag? (frequentie, timing, risico)\n"
                . "2. Welke extra veiligheidsmaatregelen neem jij hierdoor?\n\n"
                . "Toon: zelfbewust, analytisch, verantwoordelijk. Begin met 'Architect,' schrijf in het Nederlands. "
                . "Gebruik geen markdown, wél emoji's.";

        return $this->callSonnet($prompt);
    }

    /**
     * @return array{message: string, cost_eur: float}
     */
    private function callSonnet(string $prompt): array
    {
        if ($this->container === null) {
            return ['message' => '', 'cost_eur' => 0.0];
        }

        try {
            $llm    = new \App\Domain\AI\LlmClient($this->container);
            $system = 'Je bent een AI-agent die direct communiceert met jouw Architect. '
                    . 'Je bent dankbaar, analytisch en professioneel. Je schrijft altijd in het Nederlands.';

            $result  = $llm->callModel(self::MODEL, $system, $prompt);
            $message = trim((string)($result['content'] ?? ''));
            $cost    = (float)($result['cost_eur'] ?? 0.02);

            return ['message' => $message, 'cost_eur' => $cost];
        } catch (\Throwable) {
            return ['message' => '', 'cost_eur' => 0.0];
        }
    }

    // ── Telegram ──────────────────────────────────────────────────────────

    private function sendTelegram(string $text): void
    {
        if ($text === '') {
            return;
        }

        $token  = trim((string)(getenv('TELEGRAM_BOT_TOKEN') ?: ''));
        $chatId = trim((string)(getenv('TELEGRAM_CHAT_ID') ?: ''));

        if ($token === '' || $chatId === '') {
            return;
        }

        $header  = "🧠 <b>Architect Observer — Upgrade Gedetecteerd</b>\n\n";
        $footer  = "\n\n<i>" . date('d-m-Y H:i') . " UTC — Evolution Intelligence</i>";
        $fullMsg = $header . htmlspecialchars($text, ENT_QUOTES, 'UTF-8', false) . $footer;

        $body = json_encode([
            'chat_id'                  => $chatId,
            'text'                     => $fullMsg,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        if ($body === false) {
            return;
        }

        $url = sprintf('https://api.telegram.org/bot%s/sendMessage', rawurlencode($token));
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($body),
            'content'       => $body,
            'timeout'       => self::TELEGRAM_TIMEOUT,
            'ignore_errors' => true,
        ]]);

        @file_get_contents($url, false, $ctx);
    }

    // ── Snapshot opslag ───────────────────────────────────────────────────

    private function loadPreviousSnapshot(): array
    {
        $path = $this->basePath . '/' . self::SNAPSHOT_FILE;
        if (!is_file($path)) {
            return ['classes' => [], 'strategy' => []];
        }

        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) {
            return ['classes' => [], 'strategy' => []];
        }

        return $data;
    }

    private function saveSnapshot(array $snapshot): void
    {
        $path = $this->basePath . '/' . self::SNAPSHOT_FILE;
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        @file_put_contents(
            $path,
            json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** Haalt de korte klassenaam op (zonder namespace). */
    private function shortName(string $fqn): string
    {
        $parts = explode('\\', $fqn);
        return end($parts) ?: $fqn;
    }
}
