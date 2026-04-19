<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\AssetManager;
use App\Core\Config;
use App\Core\Container;
use App\Core\Request;

/**
 * Loads storage/evolution/page_libraries.json and attaches CDN styles/scripts for the current path.
 */
final class EvolutionPageAssetInjector
{
    private const LIBRARIES_PATH = 'storage/evolution/page_libraries.json';

    public static function inject(Container $container, Request $request): void
    {
        $cfg = $container->get('config');
        if (!$cfg instanceof Config) {
            return;
        }
        $evo = $cfg->get('evolution.evolution_assets', []);
        if (is_array($evo) && !filter_var($evo['enabled'] ?? true, FILTER_VALIDATE_BOOL)) {
            return;
        }

        if (!defined('BASE_PATH')) {
            return;
        }
        $path = BASE_PATH . '/' . self::LIBRARIES_PATH;
        if (!is_file($path)) {
            return;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return;
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }
        if (!is_array($data)) {
            return;
        }
        $rules = $data['rules'] ?? null;
        if (!is_array($rules) || $rules === []) {
            return;
        }

        $reqPath = $request->path;
        if ($reqPath === '') {
            $reqPath = '/';
        }

        $bestLen = -1;
        $selected = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $prefix = trim((string) ($rule['path_prefix'] ?? ''));
            if ($prefix === '') {
                continue;
            }
            if (!str_starts_with($reqPath, $prefix)) {
                continue;
            }
            $len = strlen($prefix);
            if ($len > $bestLen) {
                $bestLen = $len;
                $selected = [$rule];
            } elseif ($len === $bestLen && $len >= 0) {
                $selected[] = $rule;
            }
        }

        if ($selected === []) {
            return;
        }

        /** @var AssetManager $assets */
        $assets = $container->get('assets');

        foreach ($selected as $rule) {
            foreach ($rule['styles'] ?? [] as $url) {
                if (!is_string($url) || !EvolutionCdnPolicy::isAllowedUrl($url, $cfg)) {
                    continue;
                }
                if (!$assets->hasStyle($url)) {
                    $assets->addStyle($url);
                }
            }
            foreach ($rule['scripts'] ?? [] as $item) {
                $url = '';
                $attrs = ['defer' => true];
                if (is_string($item)) {
                    $url = $item;
                } elseif (is_array($item)) {
                    $url = (string) ($item['url'] ?? '');
                    foreach (['defer', 'async', 'crossorigin', 'integrity', 'data-load-order'] as $ak) {
                        if (array_key_exists($ak, $item)) {
                            $attrs[$ak] = $item[$ak];
                        }
                    }
                }
                if ($url === '' || !EvolutionCdnPolicy::isAllowedUrl($url, $cfg)) {
                    continue;
                }
                if (!$assets->hasScript($url)) {
                    $assets->addScript($url, $attrs);
                }
            }
        }
    }
}
