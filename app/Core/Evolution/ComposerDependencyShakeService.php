<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Heuristic: composer.json require packages whose PSR-4 namespaces never appear in application source.
 */
final class ComposerDependencyShakeService
{
    /**
     * @return array{ok: bool, likely_unused: list<array{package: string, namespaces: list<string>}>, scanned_files: int}
     */
    public function analyze(): array
    {
        $lockPath = BASE_PATH . '/composer.lock';
        $jsonPath = BASE_PATH . '/composer.json';
        if (!is_file($lockPath) || !is_file($jsonPath)) {
            return ['ok' => false, 'likely_unused' => [], 'scanned_files' => 0];
        }
        $composerJson = json_decode((string) file_get_contents($jsonPath), true);
        if (!is_array($composerJson)) {
            return ['ok' => false, 'likely_unused' => [], 'scanned_files' => 0];
        }
        $required = $composerJson['require'] ?? [];
        if (!is_array($required)) {
            return ['ok' => false, 'likely_unused' => [], 'scanned_files' => 0];
        }

        $lock = json_decode((string) file_get_contents($lockPath), true);
        if (!is_array($lock)) {
            return ['ok' => false, 'likely_unused' => [], 'scanned_files' => 0];
        }
        $packages = $lock['packages'] ?? [];
        if (!is_array($packages)) {
            return ['ok' => false, 'likely_unused' => [], 'scanned_files' => 0];
        }

        $lockByName = [];
        foreach ($packages as $pkg) {
            if (is_array($pkg) && isset($pkg['name'])) {
                $lockByName[(string) $pkg['name']] = $pkg;
            }
        }

        $haystack = $this->concatPhpSources();
        $fileCount = substr_count($haystack, "\n//file:");

        $unused = [];
        foreach ($required as $name => $_ver) {
            $name = (string) $name;
            if ($name === 'php' || str_starts_with($name, 'ext-')) {
                continue;
            }
            $pkg = $lockByName[$name] ?? null;
            if (!is_array($pkg)) {
                continue;
            }
            $nsList = $this->namespacesFromPackage($pkg);
            if ($nsList === []) {
                continue;
            }
            $any = false;
            foreach ($nsList as $ns) {
                $needle = str_replace('\\\\', '\\', $ns);
                if (str_contains($haystack, $needle) || str_contains($haystack, str_replace('\\', '\\\\', $needle))) {
                    $any = true;
                    break;
                }
            }
            if (!$any) {
                $unused[] = ['package' => $name, 'namespaces' => $nsList];
            }
        }

        return ['ok' => true, 'likely_unused' => array_slice($unused, 0, 25), 'scanned_files' => $fileCount];
    }

    public function promptSection(): string
    {
        $a = $this->analyze();
        if (!$a['ok'] || $a['likely_unused'] === []) {
            return '';
        }
        $lines = ["\n\nCOMPOSER_SHAKE (packages in composer.json — namespaces niet gevonden in src/; controleer vóór verwijderen):"];
        foreach (array_slice($a['likely_unused'], 0, 15) as $u) {
            $ns = implode(', ', $u['namespaces']);
            $lines[] = '  - ' . $u['package'] . ' [' . $ns . ']';
        }
        $lines[] = 'Gescand: ~' . $a['scanned_files'] . ' PHP-bestanden. (Heuristiek: kan false positives geven als dynamische calls.)';

        return implode("\n", $lines);
    }

    private function concatPhpSources(): string
    {
        $roots = [BASE_PATH . '/src', BASE_PATH . '/ai_bridge.php', BASE_PATH . '/web/index.php'];
        $buf = '';
        foreach ($roots as $root) {
            if (is_file($root)) {
                $buf .= "\n//file:" . basename($root) . "\n" . (string) file_get_contents($root);
                continue;
            }
            if (!is_dir($root)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                /** @var \SplFileInfo $f */
                if (!$f->isFile() || $f->getExtension() !== 'php') {
                    continue;
                }
                $path = $f->getPathname();
                if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                    continue;
                }
                $buf .= "\n//file:" . $f->getFilename() . "\n" . (string) file_get_contents($path);
                if (strlen($buf) > 2_000_000) {
                    break 2;
                }
            }
        }

        return $buf;
    }

    /**
     * @param array<string, mixed> $pkg composer.lock package entry
     *
     * @return list<string>
     */
    private function namespacesFromPackage(array $pkg): array
    {
        $out = [];
        $autoload = $pkg['autoload'] ?? [];
        if (!is_array($autoload)) {
            return [];
        }
        $psr4 = $autoload['psr-4'] ?? [];
        if (is_array($psr4)) {
            foreach ($psr4 as $ns => $_dir) {
                $ns = trim((string) $ns);
                if ($ns !== '') {
                    $out[] = rtrim($ns, '\\');
                }
            }
        }

        return array_slice(array_values(array_unique($out)), 0, 5);
    }
}
