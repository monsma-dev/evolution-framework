<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;

/**
 * Resolves OpenAI / Anthropic API keys from merged config.
 * Bootstrap keeps evolution.* and ai.* in sync; this also falls back so partial configs still work.
 */
final class EvolutionProviderKeys
{
    public static function anthropic(Config $config): string
    {
        $evo = $config->get('evolution', []);
        $anth = is_array($evo) ? ($evo['anthropic'] ?? []) : [];
        $key = trim((string)($anth['api_key'] ?? ''));
        if ($key !== '') {
            return $key;
        }
        $ai = $config->get('ai', []);
        $aiA = is_array($ai) ? ($ai['anthropic'] ?? []) : [];
        $key = trim((string)($aiA['api_key'] ?? ''));
        if ($key !== '') {
            return $key;
        }

        return trim((string)($config->get('ai.anthropic_api_key', '')));
    }

    /**
     * @param  bool $includeAssistant  If true, also use assistant.api_key (e.g. Supreme Synthesis).
     */
    public static function openAi(Config $config, bool $includeAssistant = false): string
    {
        $evo = $config->get('evolution', []);
        $oai = is_array($evo) ? ($evo['openai'] ?? []) : [];
        $key = trim((string)($oai['api_key'] ?? ''));
        if ($key !== '') {
            return $key;
        }
        $ai = $config->get('ai', []);
        $aiO = is_array($ai) ? ($ai['openai'] ?? []) : [];
        $key = trim((string)($aiO['api_key'] ?? ''));
        if ($key !== '') {
            return $key;
        }
        $legacy = trim((string)($config->get('ai.openai_api_key', '')));
        if ($legacy !== '') {
            return $legacy;
        }
        if ($includeAssistant) {
            return trim((string)($config->get('assistant.api_key', '')));
        }

        return '';
    }
}
