<?php

declare(strict_types=1);

namespace App\Core\Evolution;

use App\Core\Config;
use App\Core\Container;

/**
 * Headless screenshot via tooling/scripts/architect-screenshot.mjs (Playwright).
 */
final class VisualCaptureService
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array{ok: bool, base64?: string, path?: string, width?: int, height?: int, error?: string}
     */
    public function captureAbsoluteUrl(string $url, int $width, int $height, int $waitMs = 800): array
    {
        $config = $this->container->get('config');
        $err = $this->assertUrlAllowed($url, $config);
        if ($err !== null) {
            return ['ok' => false, 'error' => $err];
        }

        $dir = BASE_PATH . '/data/evolution/screenshots';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Cannot create screenshot directory'];
        }

        $name = 'cap-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.png';
        $out = $dir . '/' . $name;

        $script = BASE_PATH . '/tooling/scripts/architect-screenshot.mjs';
        if (!is_file($script)) {
            return ['ok' => false, 'error' => 'Screenshot script missing (tooling/scripts/architect-screenshot.mjs)'];
        }

        $node = NodeBinaryResolver::resolvedShellArg($config);
        $cmd = $node . ' ' . escapeshellarg($script) . ' '
            . escapeshellarg($url) . ' '
            . escapeshellarg($out) . ' '
            . $width . ' '
            . $height . ' '
            . max(0, $waitMs) . ' 2>&1';

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $joined = trim(implode("\n", $output));

        if ($code !== 0 || !is_file($out)) {
            EvolutionLogger::log('visual_capture', 'failed', ['cmd' => $cmd, 'out' => $joined, 'code' => $code]);

            return ['ok' => false, 'error' => $joined !== '' ? $joined : 'Screenshot command failed'];
        }

        $raw = @file_get_contents($out);
        if (!is_string($raw) || $raw === '') {
            return ['ok' => false, 'error' => 'Empty screenshot file'];
        }

        EvolutionLogger::log('visual_capture', 'ok', ['url' => $url, 'w' => $width, 'h' => $height]);

        return [
            'ok' => true,
            'path' => $out,
            'base64' => base64_encode($raw),
            'width' => $width,
            'height' => $height,
        ];
    }

    private function assertUrlAllowed(string $url, Config $config): ?string
    {
        $u = trim($url);
        if ($u === '') {
            return 'Empty URL';
        }
        $parts = parse_url($u);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return 'Invalid URL';
        }
        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return 'Only http(s) URLs are allowed';
        }

        $host = strtolower((string)$parts['host']);
        $allowed = $this->allowedHosts($config);
        if (!in_array($host, $allowed, true)) {
            return 'Host not allowed for capture (SSRF protection). Configure evolution.screenshot.allowed_hosts or use site URL host.';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function allowedHosts(Config $config): array
    {
        $out = ['localhost', '127.0.0.1', '[::1]'];
        $site = (string)$config->get('site.url', '');
        if ($site !== '') {
            $h = parse_url($site, PHP_URL_HOST);
            if (is_string($h) && $h !== '') {
                $out[] = strtolower($h);
            }
        }
        $evo = $config->get('evolution', []);
        if (is_array($evo)) {
            $cap = $evo['screenshot'] ?? [];
            if (is_array($cap) && isset($cap['allowed_hosts']) && is_array($cap['allowed_hosts'])) {
                foreach ($cap['allowed_hosts'] as $h) {
                    $h = strtolower(trim((string)$h));
                    if ($h !== '') {
                        $out[] = $h;
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }
}
