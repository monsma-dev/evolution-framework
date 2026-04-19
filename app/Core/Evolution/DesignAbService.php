<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Lightweight design A/B registry (variants + click counters) for AutoTuner-style experiments.
 */
final class DesignAbService
{
    /**
     * @return array{experiments: list<array<string, mixed>>}
     */
    public function listExperiments(): array
    {
        $data = $this->readFile();

        return ['experiments' => $data['experiments'] ?? []];
    }

    /**
     * @param array{id: string, label: string, variants: list<array{name: string, css_snippet: string}>} $payload
     * @return array{ok: bool, error?: string}
     */
    public function saveExperiment(array $payload): array
    {
        $id = trim((string)($payload['id'] ?? ''));
        if ($id === '' || !preg_match('/^[a-z0-9_\-]{1,64}$/', $id)) {
            return ['ok' => false, 'error' => 'Invalid experiment id'];
        }
        $label = trim((string)($payload['label'] ?? $id));
        $variants = $payload['variants'] ?? [];
        if (!is_array($variants) || $variants === []) {
            return ['ok' => false, 'error' => 'variants required'];
        }
        $norm = [];
        foreach ($variants as $v) {
            if (!is_array($v)) {
                continue;
            }
            $name = trim((string)($v['name'] ?? ''));
            $css = (string)($v['css_snippet'] ?? '');
            if ($name === '') {
                continue;
            }
            $norm[] = [
                'name' => $name,
                'css_snippet' => $css,
                'clicks' => (int)($v['clicks'] ?? 0),
            ];
        }
        if ($norm === []) {
            return ['ok' => false, 'error' => 'No valid variants'];
        }

        $data = $this->readFile();
        $list = $data['experiments'] ?? [];
        if (!is_array($list)) {
            $list = [];
        }
        $found = false;
        foreach ($list as $i => $ex) {
            if (is_array($ex) && ($ex['id'] ?? '') === $id) {
                $list[$i] = [
                    'id' => $id,
                    'label' => $label,
                    'active_variant' => (int)($ex['active_variant'] ?? 0),
                    'variants' => $norm,
                ];
                $found = true;
                break;
            }
        }
        if (!$found) {
            $list[] = [
                'id' => $id,
                'label' => $label,
                'active_variant' => 0,
                'variants' => $norm,
            ];
        }

        $this->writeFile(['experiments' => $list]);
        EvolutionLogger::log('ab_test', 'save', ['id' => $id]);

        return ['ok' => true];
    }

    public function recordClick(string $experimentId, string $variantName): array
    {
        $experimentId = trim($experimentId);
        $variantName = trim($variantName);
        $data = $this->readFile();
        $list = $data['experiments'] ?? [];
        if (!is_array($list)) {
            return ['ok' => false, 'error' => 'No experiments'];
        }
        foreach ($list as $i => $ex) {
            if (!is_array($ex) || ($ex['id'] ?? '') !== $experimentId) {
                continue;
            }
            $vars = $ex['variants'] ?? [];
            if (!is_array($vars)) {
                continue;
            }
            foreach ($vars as $j => $v) {
                if (is_array($v) && ($v['name'] ?? '') === $variantName) {
                    $vars[$j]['clicks'] = (int)($v['clicks'] ?? 0) + 1;
                    $list[$i]['variants'] = $vars;
                    $this->writeFile(['experiments' => $list]);

                    return ['ok' => true];
                }
            }
        }

        return ['ok' => false, 'error' => 'Experiment or variant not found'];
    }

    /**
     * @return array<string, mixed>
     */
    private function readFile(): array
    {
        $path = $this->filePath();
        if (!is_file($path)) {
            return ['experiments' => []];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return ['experiments' => []];
        }
        $j = json_decode($raw, true);

        return is_array($j) ? $j : ['experiments' => []];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeFile(array $data): void
    {
        $path = $this->filePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    private function filePath(): string
    {
        return BASE_PATH . '/data/evolution/ab_experiments.json';
    }
}
