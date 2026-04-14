<?php

declare(strict_types=1);

namespace App\Core\Evolution;

/**
 * Generates a stable, server-specific machine fingerprint for license binding.
 *
 * Priority order (AWS c7g / Linux):
 *   1. AWS EC2 instance-id (most stable, unique per instance)
 *   2. /etc/machine-id  (systemd, present on all modern Linux)
 *   3. Primary NIC MAC address
 *   4. hostname + kernel info (last resort)
 *
 * The final ID is SHA-256 of the raw identifier, prefixed with the source type.
 * This makes it opaque while remaining deterministic.
 */
final class MachineFingerprintService
{
    private const IMDS_URL    = 'http://169.254.169.254/latest/meta-data/instance-id';
    private const MACHINE_ID  = '/etc/machine-id';
    private const IMDS_TIMEOUT = 2;

    public static function generate(): string
    {
        [$raw, $source] = self::collect();
        return $source . ':' . hash('sha256', trim($raw));
    }

    /**
     * @return array{0: string, 1: string} [raw, source]
     */
    private static function collect(): array
    {
        // 1. AWS IMDS — instance-id (only works on EC2)
        $instanceId = self::fetchImds();
        if ($instanceId !== '') {
            return [$instanceId, 'aws'];
        }

        // 2. systemd /etc/machine-id
        if (is_readable(self::MACHINE_ID)) {
            $mid = trim((string)@file_get_contents(self::MACHINE_ID));
            if ($mid !== '') {
                return [$mid, 'mid'];
            }
        }

        // 3. Primary NIC MAC (first non-loopback interface)
        $mac = self::primaryMac();
        if ($mac !== '') {
            return [$mac, 'mac'];
        }

        // 4. Hostname + kernel fallback
        return [php_uname('n') . '|' . php_uname('r'), 'host'];
    }

    private static function fetchImds(): string
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout'        => self::IMDS_TIMEOUT,
                'ignore_errors'  => true,
                'method'         => 'GET',
            ],
        ]);
        $result = @file_get_contents(self::IMDS_URL, false, $ctx);
        return is_string($result) ? trim($result) : '';
    }

    private static function primaryMac(): string
    {
        if (!is_dir('/sys/class/net')) {
            return '';
        }
        $ifaces = @scandir('/sys/class/net') ?: [];
        foreach ($ifaces as $iface) {
            if ($iface === '.' || $iface === '..' || $iface === 'lo') {
                continue;
            }
            $addr = @file_get_contents("/sys/class/net/{$iface}/address");
            if (is_string($addr)) {
                $mac = trim($addr);
                if ($mac !== '' && $mac !== '00:00:00:00:00:00') {
                    return $mac;
                }
            }
        }
        return '';
    }
}
