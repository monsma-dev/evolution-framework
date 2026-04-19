<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;
use OpenAI;
use Throwable;

/**
 * Officier van Justitie: vertaalt afwijzing / risico naar een concrete "aanklacht" (goedkoop model).
 */
final class EvolutionProsecutorService
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{ok: bool, charge?: string, error?: string}
     */
    public function formulateCharge(Config $config, string $masterRejection, string $architectProposalSummary): array
    {
        $meet = $config->get('evolution.consensus.meeting', []);
        $m = is_array($meet) ? ($meet['prosecutor_model'] ?? null) : null;
        $model = (is_string($m) && $m !== '') ? $m : 'gpt-4o-mini';
        $key = EvolutionProviderKeys::openAi($config, true);
        if ($key === '') {
            return ['ok' => false, 'error' => 'Missing OpenAI API key'];
        }

        $prompt = "Master rejected a change. Summarize the specific violation or risk as a short formal \"charge\" (max 120 words). "
            . "Be concrete (e.g. sandbox boundary, budget, breaking PHP API). No markdown.\n\n"
            . "Master rejection:\n{$masterRejection}\n\nArchitect summary:\n{$architectProposalSummary}";

        try {
            $client = OpenAI::factory()->withApiKey($key)->make();
            $r = $client->chat()->create([
                'model' => $model,
                'max_tokens' => 400,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a precise technical prosecutor. Output plain text only.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);
            $text = trim((string) ($r->choices[0]->message->content ?? ''));

            $monitor = new AiCreditMonitor($config);
            $monitor->recordEstimatedTurn($model, (int) ceil(strlen($prompt) / 4), 200, []);

            return ['ok' => true, 'charge' => $text];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
