<?php

declare(strict_types=1);

namespace App\Core\Evolution\Cognitive;

use App\Core\Container;
use App\Domain\Web\Models\ReasoningTraceModel;

/**
 * InsightGenerator — renders a resolved ReasoningTrace + trade outcome into
 * copy-paste-ready marketing / client-communication snippets.
 *
 * Deterministic, template-based, NO LLM calls. Pure string assembly from
 * the trace data. Output is always the admin's to review before sharing.
 *
 * Produces five sections per insight:
 *   - title:        short headline (< 80 chars)
 *   - body_nl:      Dutch long-form (markdown-friendly, ~150-250 words)
 *   - body_en:      English long-form (same structure)
 *   - hashtags:     list of lowercase tags suitable for social
 *   - snippets:     short-form copy (tweet, LinkedIn post, client email subject+body)
 *
 * Privacy rules (same as EvidenceExporter):
 *   - never include wallet addresses or tx hashes (they're not in the trace anyway)
 *   - profit_eur is rounded to the nearest whole euro before composition
 *   - spot prices are not quoted numerically; only described qualitatively
 *     ("near €2140 band") if the context supplies them
 */
final class InsightGenerator
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Generate insight bundle for a (correlation_id, profit, context) triple.
     *
     * @param array<string,mixed> $context Optional: pair, delta_pct, horizon_hours, network, spot_price_band
     * @return array{ok:bool, reason?:string, title?:string, body_nl?:string, body_en?:string, hashtags?:list<string>, snippets?:array<string,string>}
     */
    public function fromTrace(string $correlationId, float $profitEur, array $context = []): array
    {
        $model = new ReasoningTraceModel($this->container);
        $row   = $model->findByCorrelationId($correlationId);
        if ($row === null) {
            return ['ok' => false, 'reason' => 'trace_not_found:' . $correlationId];
        }
        $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
        if (!is_array($payload)) {
            return ['ok' => false, 'reason' => 'trace_payload_corrupt'];
        }

        return $this->compose($payload, $profitEur, $context);
    }

    /**
     * Variant you already have the decoded trace for.
     *
     * @param array<string,mixed> $trace    Decoded ReasoningTrace->toArray()
     * @param array<string,mixed> $context  Same shape as fromTrace
     * @return array{ok:bool, title:string, body_nl:string, body_en:string, hashtags:list<string>, snippets:array<string,string>}
     */
    public function compose(array $trace, float $profitEur, array $context = []): array
    {
        $direction = strtoupper((string) ($trace['direction'] ?? 'NEUTRAL'));
        $aggregate = (float)  ($trace['aggregate_score'] ?? 0.0);
        $confidence= (float)  ($trace['confidence']      ?? 0.0);
        $steps     = (array)  ($trace['steps']           ?? []);

        $pair     = (string) ($context['pair']          ?? 'ETH/EUR');
        $network  = (string) ($context['network']       ?? ($trace['input_snapshot']['network_label'] ?? 'Base'));
        $horizon  = (int)    ($context['horizon_hours'] ?? ($trace['input_snapshot']['horizon_hours'] ?? 4));
        $deltaPct = isset($context['delta_pct']) && is_numeric($context['delta_pct'])
            ? round((float) $context['delta_pct'], 1)
            : null;

        $profitRounded = (int) round($profitEur);
        $confidencePct = (int) round($confidence * 100);

        $leadStep = $this->pickLeadStep($steps);
        $calibration = (array) ($trace['calibration'] ?? []);
        $calibrationFactor = isset($calibration['factor']) ? round((float) $calibration['factor'], 2) : null;

        $title = sprintf(
            '%s %s voorspelling op %s leverde +€%d op (%dh, %d%% vertrouwen)',
            $this->directionEmoji($direction),
            $this->directionLabel($direction, 'nl'),
            $pair,
            $profitRounded,
            $horizon,
            $confidencePct
        );

        $bodyNl = $this->renderBody('nl', [
            'direction'       => $direction,
            'direction_label' => $this->directionLabel($direction, 'nl'),
            'pair'            => $pair,
            'network'         => $network,
            'horizon'         => $horizon,
            'profit'          => $profitRounded,
            'delta'           => $deltaPct,
            'confidence_pct'  => $confidencePct,
            'aggregate'       => $aggregate,
            'lead_step'       => $leadStep,
            'step_count'      => count($steps),
            'calibration'     => $calibrationFactor,
        ]);

        $bodyEn = $this->renderBody('en', [
            'direction'       => $direction,
            'direction_label' => $this->directionLabel($direction, 'en'),
            'pair'            => $pair,
            'network'         => $network,
            'horizon'         => $horizon,
            'profit'          => $profitRounded,
            'delta'           => $deltaPct,
            'confidence_pct'  => $confidencePct,
            'aggregate'       => $aggregate,
            'lead_step'       => $leadStep,
            'step_count'      => count($steps),
            'calibration'     => $calibrationFactor,
        ]);

        $hashtags = $this->hashtagsFor($direction, $network, $pair, (bool) $calibrationFactor);
        $snippets = $this->snippets($title, $direction, $pair, $profitRounded, $horizon, $confidencePct, $deltaPct, $network);

        return [
            'ok'       => true,
            'title'    => $title,
            'body_nl'  => $bodyNl,
            'body_en'  => $bodyEn,
            'hashtags' => $hashtags,
            'snippets' => $snippets,
        ];
    }

    // ── composition helpers ───────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $steps
     * @return array{policy:string,direction:string,rationale:string,contribution:float}|null
     */
    private function pickLeadStep(array $steps): ?array
    {
        $best = null;
        $bestWeight = -INF;
        foreach ($steps as $s) {
            if (!is_array($s)) {
                continue;
            }
            $dir = strtoupper((string) ($s['direction'] ?? 'NEUTRAL'));
            if ($dir === 'NEUTRAL') {
                continue;
            }
            $w = (float) ($s['weight'] ?? 0) * (float) ($s['confidence'] ?? 0);
            if ($w > $bestWeight) {
                $bestWeight = $w;
                $best = [
                    'policy'       => (string) ($s['policy']       ?? ''),
                    'direction'    => $dir,
                    'rationale'    => (string) ($s['rationale']    ?? ''),
                    'contribution' => (float)  ($s['contribution'] ?? 0),
                ];
            }
        }
        return $best;
    }

    /**
     * @param array<string,mixed> $v
     */
    private function renderBody(string $locale, array $v): string
    {
        $lead = $v['lead_step'];

        if ($locale === 'nl') {
            $paragraphs = [];
            $paragraphs[] = sprintf(
                'Onze autonome agent heeft vandaag een %s-voorspelling gedaan op %s (%s) met een horizon van %d uur. De ReasoningEngine collapse-te %d beleidsregels in een enkele richting met %d%% vertrouwen.',
                strtolower((string) $v['direction_label']),
                (string) $v['pair'],
                (string) $v['network'],
                (int) $v['horizon'],
                (int) $v['step_count'],
                (int) $v['confidence_pct']
            );
            if ($lead !== null) {
                $paragraphs[] = sprintf(
                    'De doorslag werd gegeven door de `%s` policy: "%s"',
                    $lead['policy'],
                    $lead['rationale']
                );
            }
            if ($v['calibration'] !== null && $v['calibration'] < 1.0) {
                $paragraphs[] = sprintf(
                    'Het calibratie-roer verlaagde het ruwe signaal met factor ×%.2f op basis van historische nauwkeurigheid — een ingebouwde rem tegen overmoed.',
                    (float) $v['calibration']
                );
            }
            if ($v['delta'] !== null) {
                $paragraphs[] = sprintf(
                    'Resultaat na afloop van het venster: %s%.1f%%. Opbrengst: +€%d.',
                    (float) $v['delta'] >= 0 ? '+' : '',
                    (float) $v['delta'],
                    (int) $v['profit']
                );
            } else {
                $paragraphs[] = sprintf('Opbrengst: +€%d.', (int) $v['profit']);
            }
            $paragraphs[] = 'Dit is geen marketing — het is een volledig reproduceerbaar redeneer-spoor. De onderliggende trace staat permanent gearchiveerd en kan via /evidence worden geverifieerd.';
            return implode("\n\n", $paragraphs);
        }

        // English
        $paragraphs = [];
        $paragraphs[] = sprintf(
            'Our autonomous agent issued a %s call on %s (%s) with a %d-hour horizon. The ReasoningEngine collapsed %d policies into a single direction at %d%% confidence.',
            strtolower((string) $v['direction_label']),
            (string) $v['pair'],
            (string) $v['network'],
            (int) $v['horizon'],
            (int) $v['step_count'],
            (int) $v['confidence_pct']
        );
        if ($lead !== null) {
            $paragraphs[] = sprintf(
                'The deciding vote came from the `%s` policy: "%s"',
                $lead['policy'],
                $lead['rationale']
            );
        }
        if ($v['calibration'] !== null && $v['calibration'] < 1.0) {
            $paragraphs[] = sprintf(
                'The calibration rudder reduced the raw signal by factor ×%.2f based on historical accuracy — a built-in brake against overconfidence.',
                (float) $v['calibration']
            );
        }
        if ($v['delta'] !== null) {
            $paragraphs[] = sprintf(
                'Result after the window closed: %s%.1f%%. Realised profit: +€%d.',
                (float) $v['delta'] >= 0 ? '+' : '',
                (float) $v['delta'],
                (int) $v['profit']
            );
        } else {
            $paragraphs[] = sprintf('Realised profit: +€%d.', (int) $v['profit']);
        }
        $paragraphs[] = 'This is not marketing copy — it is a fully reproducible reasoning trace. The underlying audit trail is archived permanently and can be verified at /evidence.';
        return implode("\n\n", $paragraphs);
    }

    /**
     * @return list<string>
     */
    private function hashtagsFor(string $direction, string $network, string $pair, bool $calibrated): array
    {
        $tags = ['autonomousagent', 'reasoningengine', 'evidenceofintelligence'];
        $pairTag = strtolower(str_replace('/', '', $pair));
        if ($pairTag !== '') {
            $tags[] = $pairTag;
        }
        if ($network !== '') {
            $tags[] = strtolower(str_replace(' ', '', $network));
        }
        if ($direction === 'UP') {
            $tags[] = 'bullishcall';
        } elseif ($direction === 'DOWN') {
            $tags[] = 'bearishcall';
        }
        if ($calibrated) {
            $tags[] = 'calibratedai';
        }
        return array_values(array_unique($tags));
    }

    /**
     * @return array<string,string>
     */
    private function snippets(string $title, string $dir, string $pair, int $profit, int $horizon, int $confPct, ?float $delta, string $network): array
    {
        $deltaPart = $delta !== null
            ? sprintf(' %+.1f%% gerealiseerd.', $delta)
            : '';
        return [
            'tweet' => sprintf(
                '%s voorspelling op %s (%s) — +€%d in %dh, %d%% vertrouwen.%s Volledig spoor: /evidence',
                $this->directionLabel($dir, 'nl'),
                $pair,
                $network,
                $profit,
                $horizon,
                $confPct,
                $deltaPart
            ),
            'linkedin' => sprintf(
                "%s\n\nEen autonome agent nam deze beslissing op basis van een reproduceerbaar spoor van %d beleidsregels met calibratie op historische nauwkeurigheid. Geen menselijke tussenkomst. Volledige trace en anonimiseerde JSON staan publiek op /evidence.",
                $title,
                4
            ),
            'email_subject' => sprintf('Nieuwe winstgevende trade: %s %s (+€%d)', $dir, $pair, $profit),
            'email_body'    => sprintf(
                "Hallo,\n\nEen korte update: onze autonome agent heeft zojuist een %s-positie op %s afgesloten met +€%d winst (%dh horizon, %d%% vertrouwen).\n\nDe volledige, onafhankelijk-verifieerbare ReasoningTrace is gearchiveerd en kan op aanvraag worden gedeeld.\n\nMet vriendelijke groet,\nHet team",
                strtolower($this->directionLabel($dir, 'nl')),
                $pair,
                $profit,
                $horizon,
                $confPct
            ),
        ];
    }

    private function directionEmoji(string $direction): string
    {
        return match ($direction) {
            'UP'    => '^',
            'DOWN'  => 'v',
            'VETO'  => 'X',
            default => '=',
        };
    }

    private function directionLabel(string $direction, string $locale): string
    {
        if ($locale === 'nl') {
            return match ($direction) {
                'UP'    => 'STIJGING',
                'DOWN'  => 'DALING',
                'VETO'  => 'BLOKKERING',
                default => 'NEUTRAAL',
            };
        }
        return match ($direction) {
            'UP'    => 'BULLISH',
            'DOWN'  => 'BEARISH',
            'VETO'  => 'BLOCKED',
            default => 'NEUTRAL',
        };
    }
}
