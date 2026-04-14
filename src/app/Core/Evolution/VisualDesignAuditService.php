<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Container;
use OpenAI;
use Throwable;

/**
 * GPT-4o vision: design audit vs modern UX + optional brand notes.
 */
final class VisualDesignAuditService
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{ok: bool, raw_json?: array, error?: string}
     */
    public function auditPngBase64(string $base64Png, string $userGoal, string $pageContext = ''): array
    {
        $config = $this->container->get('config');
        $apiKey = trim((string)$config->get('ai.openai.api_key', $config->get('assistant.api_key', '')));
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'Missing OpenAI API key'];
        }

        $evo = $config->get('evolution', []);
        $da = is_array($evo) ? ($evo['design_audit'] ?? []) : [];
        $model = is_array($da) ? (string)($da['vision_model'] ?? 'gpt-4o') : 'gpt-4o';
        $brandNotes = '';
        if (is_array($da)) {
            $path = trim((string)($da['brand_notes_path'] ?? ''));
            if ($path !== '') {
                $full = $path;
                if (!(str_starts_with($full, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $full) === 1)) {
                    $full = BASE_PATH . '/' . ltrim($full, '/');
                }
                if (is_file($full)) {
                    $brandNotes = (string)@file_get_contents($full);
                }
            }
        }

        $system = <<<PROMPT
You are a senior UX/UI designer auditing a web screenshot. Evaluate spacing, contrast, typography hierarchy, tap targets (mobile), alignment, and obvious visual bugs (overlap, clipping).
Respond with a single JSON object (no markdown) of this shape:
{
  "summary": "short overview",
  "severity": "low|medium|high",
  "issues": [{"title":"","detail":"","area":"layout|color|type|spacing|accessibility|other"}],
  "strengths": ["optional"],
  "suggested_css": "CSS snippet to improve (only declarations, can use media queries)",
  "suggested_twig_notes": "What to change in HTML/Twig structure (no full file required)",
  "visual_bugs": ["things PHP would not detect, e.g. button overlapping text"]
}
Keep suggested_css concise and safe (no url(), no expression()).
PROMPT;
        $system .= CostGuard::promptAppend($config);
        if ($brandNotes !== '') {
            $system .= "\nBrand / house style notes:\n" . mb_substr($brandNotes, 0, 6000);
        }

        $userText = "User goal: {$userGoal}\n";
        if ($pageContext !== '') {
            $userText .= "Page context: {$pageContext}\n";
        }
        $userText .= 'Analyse the attached screenshot.';

        try {
            $client = OpenAI::factory()->withApiKey($apiKey)->make();
            $response = $client->chat()->create([
                'model' => $model,
                'max_tokens' => 4096,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $userText],
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => 'data:image/png;base64,' . $base64Png,
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
            $text = trim((string)($response->choices[0]->message->content ?? ''));
            if ($text === '') {
                return ['ok' => false, 'error' => 'Empty vision response'];
            }
            $decoded = json_decode($text, true);
            if (!is_array($decoded)) {
                return ['ok' => false, 'error' => 'Vision model returned non-JSON'];
            }

            EvolutionLogger::log('design_audit', 'complete', ['severity' => $decoded['severity'] ?? '']);

            return ['ok' => true, 'raw_json' => $decoded];
        } catch (Throwable $e) {
            EvolutionLogger::log('design_audit', 'error', ['message' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
