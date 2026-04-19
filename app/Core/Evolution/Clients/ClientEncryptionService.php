<?php

declare(strict_types=1);

namespace App\Core\Evolution\Clients;

use App\Core\Config;
use App\Core\Container;

/**
 * AES-256-GCM for client trading key material. Key derived from evolution.multi_client.encryption_secret in config.
 */
final class ClientEncryptionService
{
    private string $keyBinary;

    public function __construct(private readonly string $basePath, ?Container $container = null)
    {
        $secret = '';
        if ($container !== null) {
            /** @var Config $cfg */
            $cfg = $container->get('config');
            $evo  = $cfg->get('evolution', []);
            $mc   = is_array($evo) ? (array) ($evo['multi_client'] ?? []) : [];
            $secret = trim((string) ($mc['encryption_secret'] ?? ''));
        }
        if ($secret === '') {
            $path = $this->basePath . '/config/evolution.json';
            if (is_file($path)) {
                $j = json_decode((string) file_get_contents($path), true);
                $mc = is_array($j) ? (array) ($j['multi_client'] ?? []) : [];
                $secret = trim((string) ($mc['encryption_secret'] ?? ''));
            }
        }
        if ($secret === '' || strlen($secret) < 16) {
            throw new \RuntimeException('evolution.multi_client.encryption_secret must be set (min 16 chars) in src/config/evolution.json');
        }
        $this->keyBinary = hash('sha256', $secret, true);
    }

    public function encrypt(string $plain): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', $this->keyBinary, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            throw new \RuntimeException('Client encryption failed');
        }

        return base64_encode(json_encode([
            'v' => 1,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ct' => base64_encode($ct),
        ], JSON_THROW_ON_ERROR));
    }

    public function decrypt(string $blob): string
    {
        $raw = json_decode((string) base64_decode($blob, true), true);
        if (!is_array($raw) || !isset($raw['iv'], $raw['tag'], $raw['ct'])) {
            throw new \RuntimeException('Invalid encrypted payload');
        }
        $iv  = (string) base64_decode((string) $raw['iv'], true);
        $tag = (string) base64_decode((string) $raw['tag'], true);
        $ct  = (string) base64_decode((string) $raw['ct'], true);
        $pt  = openssl_decrypt($ct, 'aes-256-gcm', $this->keyBinary, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($pt === false) {
            throw new \RuntimeException('Client decryption failed');
        }

        return $pt;
    }
}
