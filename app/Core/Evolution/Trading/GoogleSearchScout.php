<?php

declare(strict_types=1);

namespace App\Core\Evolution\Trading;

use App\Core\Evolution\LlmClient\GeminiClient;
use Psr\Container\ContainerInterface;

/**
 * Live nieuws / publieke context via Gemini (+ optionele Google Search tool waar het model dat ondersteunt).
 * Gebruikt door AgentToolbox als aanvulling op whale/simulatie.
 */
final class GoogleSearchScout
{
    public function __construct(private readonly ?ContainerInterface $container = null)
    {
    }

    /**
     * @return array{ok: bool, summary: string, verdict: string, error: string, skipped: bool, cost_eur: float}
     */
    public function verifyLiveNews(string $query): array
    {
        if ($this->container === null || ! $this->container->has('config')) {
            return ['ok' => true, 'summary' => '', 'verdict' => 'SKIP', 'error' => '', 'skipped' => true, 'cost_eur' => 0.0];
        }

        /** @var \App\Core\Config $cfg */
        $cfg   = $this->container->get('config');
        $apiKey = trim((string)($cfg->get('ai.gemini.api_key') ?? ''));
        if ($apiKey === '') {
            return ['ok' => true, 'summary' => '', 'verdict' => 'SKIP', 'error' => '', 'skipped' => true, 'cost_eur' => 0.0];
        }

        $model = trim((string)($cfg->get('ai.gemini.model') ?? 'gemini-1.5-pro'));
        // googleSearch-tool werkt vooral op nieuwere Flash/2.x; fallback zonder tools op 1.5 Pro
        $searchModel = $model;
        if (!str_contains(strtolower($searchModel), 'flash') && !str_contains(strtolower($searchModel), '2.')) {
            $searchModel = 'gemini-2.0-flash';
        }

        $system = 'Je bent een nieuws-verificatie scout. Gebruik actuele publieke informatie waar beschikbaar. '
            . 'Antwoord kort in het Engels of Nederlands. Eindig met VERDICT: SAFE|CAUTION|RISK.';

        $client = new GeminiClient();
        $body   = [
            'tools' => [['googleSearch' => (object)[]]],
        ];

        $r = $client->completeWithTools($searchModel, $system, $query, $apiKey, $body);
        if (($r['error'] ?? '') !== '') {
            $r = $client->complete($model, $system, $query, $apiKey);
        }

        $text = trim((string)($r['content'] ?? ''));
        $up   = strtoupper($text);

        $verdict = 'CAUTION';
        if (str_contains($up, 'VERDICT: RISK') || str_contains($up, 'VERDICT:RISK')) {
            $verdict = 'RISK';
        } elseif (str_contains($up, 'VERDICT: SAFE') || str_contains($up, 'VERDICT:SAFE')) {
            $verdict = 'SAFE';
        }

        return [
            'ok'       => $verdict !== 'RISK',
            'summary'  => mb_substr($text, 0, 500),
            'verdict'  => $verdict,
            'error'    => (string)($r['error'] ?? ''),
            'skipped'  => false,
            'cost_eur' => (float)($r['cost_eur'] ?? 0.0),
        ];
    }
}
