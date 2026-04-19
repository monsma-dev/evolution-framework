<?php

declare(strict_types=1);

namespace App\Core\Evolution\Social;

/**
 * IdentityNode — Sovereign Identity for the Social Mesh.
 *
 * Generates and persists an Ed25519-compatible keypair (via OpenSSL RSA-4096 with SHA-256
 * signing) that anchors this server's identity in the Ancestral Memory.
 *
 * Storage: storage/evolution/social/identity.json
 *   {
 *     "node_id":    "<sha256 fingerprint of public key>",
 *     "public_key": "<PEM>",
 *     "created_at": "<ISO-8601>",
 *     "version":    1
 *   }
 *
 * The private key is stored separately in storage/evolution/social/identity.key (600 perms).
 */
final class IdentityNode
{
    private const STORAGE_DIR  = 'storage/evolution/social';
    private const IDENTITY_FILE = 'identity.json';
    private const KEY_FILE      = 'identity.key';
    private const KEY_BITS      = 4096;

    private string $storageDir;

    public function __construct(?string $basePath = null)
    {
        $base = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
        $this->storageDir = rtrim($base, '/') . '/' . self::STORAGE_DIR;
    }

    /** Returns the node identity, generating one if it does not exist. */
    public function identity(): array
    {
        $file = $this->storageDir . '/' . self::IDENTITY_FILE;
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && isset($data['node_id'], $data['public_key'])) {
                return $data;
            }
        }
        return $this->generate();
    }

    /** Forces generation of a new keypair. Overwrites existing identity. */
    public function generate(): array
    {
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0750, true);
        }

        $key = openssl_pkey_new([
            'private_key_bits' => self::KEY_BITS,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($key === false) {
            throw new \RuntimeException('OpenSSL keypair generation failed: ' . openssl_error_string());
        }

        openssl_pkey_export($key, $privateKeyPem);
        $details   = openssl_pkey_get_details($key);
        $publicPem = $details['key'] ?? '';
        $nodeId    = hash('sha256', $publicPem);

        $identity = [
            'node_id'    => $nodeId,
            'public_key' => $publicPem,
            'created_at' => date('c'),
            'version'    => 1,
        ];

        $keyFile = $this->storageDir . '/' . self::KEY_FILE;
        file_put_contents($keyFile, $privateKeyPem, LOCK_EX);
        chmod($keyFile, 0600);

        $identityFile = $this->storageDir . '/' . self::IDENTITY_FILE;
        file_put_contents($identityFile, json_encode($identity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

        return $identity;
    }

    /** Signs a payload string with the private key. Returns base64-encoded signature. */
    public function sign(string $payload): string
    {
        $privateKey = $this->loadPrivateKey();
        openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        return base64_encode($signature);
    }

    /** Verifies a signature against a public key PEM and payload. */
    public static function verify(string $payload, string $signatureB64, string $publicPem): bool
    {
        $key = openssl_pkey_get_public($publicPem);
        if ($key === false) {
            return false;
        }
        return openssl_verify($payload, base64_decode($signatureB64), $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /** Returns the node_id (SHA-256 fingerprint of the public key). */
    public function nodeId(): string
    {
        return $this->identity()['node_id'] ?? '';
    }

    /** Returns the public key PEM. */
    public function publicKey(): string
    {
        return $this->identity()['public_key'] ?? '';
    }

    /** Returns whether an identity exists (without generating one). */
    public function exists(): bool
    {
        $file = $this->storageDir . '/' . self::IDENTITY_FILE;
        return is_file($file);
    }

    private function loadPrivateKey(): \OpenSSLAsymmetricKey
    {
        $keyFile = $this->storageDir . '/' . self::KEY_FILE;
        if (!is_file($keyFile)) {
            throw new \RuntimeException('Private key not found. Run evolve:social-node init first.');
        }
        $pem = file_get_contents($keyFile);
        $key = openssl_pkey_get_private((string) $pem);
        if ($key === false) {
            throw new \RuntimeException('Failed to load private key: ' . openssl_error_string());
        }
        return $key;
    }
}
