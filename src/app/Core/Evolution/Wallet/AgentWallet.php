<?php

declare(strict_types=1);

namespace App\Core\Evolution\Wallet;

/**
 * AgentWallet — Sovereign EVM wallet for the Evolution AI Agent.
 *
 * Generates a secp256k1 keypair and derives an Ethereum-compatible address.
 * Works on Base, Ethereum, and all EVM chains.
 *
 * Security model:
 *   - Public address stored in evolution.json (safe to share)
 *   - Private key AES-256-GCM encrypted at rest in storage/evolution/wallet/key.enc
 *   - Encryption key derived from WALLET_PASSPHRASE env var (never stored on disk)
 *   - In production: replace passphrase with Rust-Guard HSM integration
 *
 * Requirements: PHP ext-openssl, ext-gmp (both standard in PHP 8+)
 */
final class AgentWallet
{
    private const WALLET_DIR   = 'storage/evolution/wallet';
    private const KEY_FILE     = 'storage/evolution/wallet/key.enc';
    private const WALLET_FILE  = 'storage/evolution/wallet/wallet.json';

    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 5));
    }

    /**
     * Generate a new wallet. Returns the public address.
     * Throws if a wallet already exists (use forceGenerate for override).
     */
    public function generate(bool $force = false): array
    {
        if ($this->exists() && !$force) {
            return $this->load();
        }

        $this->ensureDir();

        // Generate secp256k1 private key via OpenSSL
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'secp256k1',
        ]);

        if (!$key) {
            throw new \RuntimeException('Failed to generate secp256k1 key. Ensure OpenSSL supports secp256k1.');
        }

        // Extract private key bytes
        openssl_pkey_export($key, $privPem);
        $privKeyHex = $this->extractPrivKeyHex($privPem);

        // Extract uncompressed public key (04 + X + Y = 65 bytes)
        $details = openssl_pkey_get_details($key);
        $pubKeyHex = $this->extractPubKeyHex($details);

        // Derive Ethereum address: keccak256(pubkey[1:65])[-20:]
        $address = $this->deriveAddress($pubKeyHex);

        // Encrypt and store private key
        $this->encryptAndStoreKey($privKeyHex);

        // Store public wallet data
        $wallet = [
            'address'      => $address,
            'network'      => 'base',
            'chain_id'     => 8453,
            'created_at'   => date('c'),
            'pub_key_hex'  => $pubKeyHex,
        ];
        file_put_contents(
            $this->basePath . '/' . self::WALLET_FILE,
            json_encode($wallet, JSON_PRETTY_PRINT),
            LOCK_EX
        );

        return $wallet;
    }

    /** Load existing wallet (public data only). */
    public function load(): array
    {
        $file = $this->basePath . '/' . self::WALLET_FILE;
        if (!is_file($file)) {
            throw new \RuntimeException('No wallet found. Run: php ai_bridge.php evolve:wallet generate');
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Corrupted wallet file.');
        }
        return $data;
    }

    /** Returns true if a wallet has been generated. */
    public function exists(): bool
    {
        return is_file($this->basePath . '/' . self::WALLET_FILE);
    }

    /** Decrypt and return raw private key hex (handle with care). */
    public function decryptPrivateKey(): string
    {
        $file = $this->basePath . '/' . self::KEY_FILE;
        if (!is_file($file)) {
            throw new \RuntimeException('No encrypted key file found.');
        }
        $passphrase = $this->getPassphrase();
        $encData    = json_decode((string) file_get_contents($file), true);
        if (!is_array($encData)) {
            throw new \RuntimeException('Corrupted key file.');
        }

        $key     = hash('sha256', $passphrase, true);
        $iv      = base64_decode($encData['iv']);
        $tag     = base64_decode($encData['tag']);
        $cipher  = base64_decode($encData['cipher']);

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('Failed to decrypt private key. Wrong passphrase?');
        }
        return $plain;
    }

    /** Sign a message hash (32 bytes hex) with the agent private key. */
    public function sign(string $messageHashHex): array
    {
        $privKeyHex = $this->decryptPrivateKey();
        $privKeyBin = hex2bin($privKeyHex);

        // Rebuild PEM from raw private key
        $key = $this->importPrivKeyFromHex($privKeyHex);

        $hashBin = hex2bin(ltrim($messageHashHex, '0x'));
        openssl_sign($hashBin, $signature, $key, OPENSSL_ALGO_SHA256);

        return [
            'signature' => bin2hex($signature),
            'signer'    => $this->load()['address'],
        ];
    }

    // ── Address derivation ────────────────────────────────────────────────

    private function deriveAddress(string $pubKeyHex): string
    {
        // Remove 04 prefix (uncompressed point indicator)
        $rawPubHex = substr($pubKeyHex, 2); // 128 hex chars = 64 bytes
        $rawPubBin = hex2bin($rawPubHex);

        $keccakHash = $this->keccak256($rawPubBin);
        // Ethereum address = last 20 bytes of keccak256(pubkey)
        $address = '0x' . substr($keccakHash, -40);
        return $this->toChecksumAddress($address);
    }

    /** EIP-55 checksum encoding. */
    private function toChecksumAddress(string $address): string
    {
        $addr = strtolower(substr($address, 2));
        $hash = $this->keccak256($addr);
        $result = '0x';
        for ($i = 0; $i < 40; $i++) {
            $result .= hexdec($hash[$i]) >= 8 ? strtoupper($addr[$i]) : $addr[$i];
        }
        return $result;
    }

    // ── Keccak-256 (pure PHP) ─────────────────────────────────────────────

    private function keccak256(string $input): string
    {
        return $this->keccakHash($input, 256);
    }

    private function keccakHash(string $input, int $bits): string
    {
        $rate      = 1088; // bits (for keccak-256: rate = 1600-512)
        $capacity  = 1600 - $rate;
        $hashLen   = $bits / 8;
        $rateBytes = $rate / 8;

        // Padding
        $msg    = $input;
        $msgLen = strlen($msg);
        $msg   .= "\x01";
        $padLen = $rateBytes - ($msgLen % $rateBytes);
        if ($padLen === 0) $padLen = $rateBytes;
        $msg   .= str_repeat("\x00", $padLen - 1) . "\x80";

        // State (5x5 64-bit lanes as pairs of 32-bit ints [lo, hi])
        $state = array_fill(0, 25, [0, 0]);

        // Absorb
        $blocks = strlen($msg) / $rateBytes;
        for ($blk = 0; $blk < $blocks; $blk++) {
            $block = substr($msg, $blk * $rateBytes, $rateBytes);
            for ($i = 0; $i < $rateBytes / 8; $i++) {
                $lo = unpack('V', substr($block, $i * 8, 4))[1];
                $hi = unpack('V', substr($block, $i * 8 + 4, 4))[1];
                $state[$i][0] ^= $lo;
                $state[$i][1] ^= $hi;
            }
            $state = $this->keccakF($state);
        }

        // Squeeze
        $output = '';
        for ($i = 0; $i < $hashLen / 8; $i++) {
            $output .= pack('V', $state[$i][0]) . pack('V', $state[$i][1]);
        }
        return bin2hex(substr($output, 0, $hashLen));
    }

    private function keccakF(array $A): array
    {
        static $RC = [
            [0x00000001, 0x00000000],[0x00008082, 0x00000000],[0x0000808a, 0x80000000],
            [0x80008000, 0x80000000],[0x0000808b, 0x00000000],[0x80000001, 0x00000000],
            [0x80008081, 0x80000000],[0x00008009, 0x80000000],[0x0000008a, 0x00000000],
            [0x00000088, 0x00000000],[0x80008009, 0x00000000],[0x8000000a, 0x00000000],
            [0x8000808b, 0x00000000],[0x0000008b, 0x80000000],[0x00008089, 0x80000000],
            [0x00008003, 0x80000000],[0x00008002, 0x80000000],[0x00000080, 0x80000000],
            [0x0000800a, 0x00000000],[0x8000000a, 0x80000000],[0x80008081, 0x80000000],
            [0x00008080, 0x80000000],[0x80000001, 0x00000000],[0x80008008, 0x80000000],
        ];
        static $RHO = [1,3,6,10,15,21,28,36,45,55,2,14,27,41,56,8,25,43,62,18,39,61,20,44];
        static $PI  = [10,7,11,17,18,3,5,16,8,21,24,4,15,23,19,13,12,2,20,14,22,9,6,1];

        for ($round = 0; $round < 24; $round++) {
            // Theta
            $C = [];
            for ($x = 0; $x < 5; $x++) {
                $C[$x] = [$A[$x][0]^$A[$x+5][0]^$A[$x+10][0]^$A[$x+15][0]^$A[$x+20][0],
                          $A[$x][1]^$A[$x+5][1]^$A[$x+10][1]^$A[$x+15][1]^$A[$x+20][1]];
            }
            for ($x = 0; $x < 5; $x++) {
                $d = $this->rot64($C[($x+1)%5], 1);
                $d = [$d[0]^$C[($x+4)%5][0], $d[1]^$C[($x+4)%5][1]];
                for ($y = 0; $y < 5; $y++) { $A[$x+5*$y] = [$A[$x+5*$y][0]^$d[0], $A[$x+5*$y][1]^$d[1]]; }
            }
            // Rho & Pi
            $last = $A[1];
            for ($i = 0; $i < 24; $i++) {
                $j    = $PI[$i];
                $tmp  = $A[$j];
                $A[$j]= $this->rot64($last, $RHO[$i]);
                $last = $tmp;
            }
            // Chi
            for ($y = 0; $y < 5; $y++) {
                $T = [];
                for ($x = 0; $x < 5; $x++) { $T[$x] = $A[$x+5*$y]; }
                for ($x = 0; $x < 5; $x++) {
                    $A[$x+5*$y] = [$T[$x][0] ^ (~$T[($x+1)%5][0] & $T[($x+2)%5][0]),
                                   $T[$x][1] ^ (~$T[($x+1)%5][1] & $T[($x+2)%5][1])];
                }
            }
            // Iota
            $A[0] = [$A[0][0]^$RC[$round][0], $A[0][1]^$RC[$round][1]];
        }
        return $A;
    }

    /** Rotate a 64-bit [lo,hi] pair left by $n bits. */
    private function rot64(array $v, int $n): array
    {
        $n  = $n % 64;
        $lo = $v[0]; $hi = $v[1];
        if ($n === 0) return [$lo, $hi];
        if ($n < 32) {
            return [($lo << $n) | ($hi >> (32 - $n)), ($hi << $n) | ($lo >> (32 - $n))];
        }
        $n -= 32;
        return [($hi << $n) | ($lo >> (32 - $n)), ($lo << $n) | ($hi >> (32 - $n))];
    }

    // ── Key extraction helpers ────────────────────────────────────────────

    private function extractPrivKeyHex(string $pem): string
    {
        $key     = openssl_pkey_get_private($pem);
        $details = openssl_pkey_get_details($key);
        // EC private key is in $details['ec']['d'] as binary
        return bin2hex($details['ec']['d'] ?? '');
    }

    private function extractPubKeyHex(array $details): string
    {
        $x = bin2hex($details['ec']['x'] ?? '');
        $y = bin2hex($details['ec']['y'] ?? '');
        // Pad to 32 bytes each
        $x = str_pad($x, 64, '0', STR_PAD_LEFT);
        $y = str_pad($y, 64, '0', STR_PAD_LEFT);
        return '04' . $x . $y;
    }

    private function importPrivKeyFromHex(string $hex): \OpenSSLAsymmetricKey
    {
        // Encode as SEC1 DER then PEM for OpenSSL
        $privBin = hex2bin($hex);
        $der = "\x30\x77\x02\x01\x01\x04\x20" . $privBin
             . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
             . "\xa1\x44\x03\x42\x00";
        $pem = "-----BEGIN EC PRIVATE KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END EC PRIVATE KEY-----\n";
        return openssl_pkey_get_private($pem);
    }

    // ── Encryption / storage ──────────────────────────────────────────────

    private function encryptAndStoreKey(string $privKeyHex): void
    {
        $passphrase = $this->getPassphrase();
        $key        = hash('sha256', $passphrase, true);
        $iv         = random_bytes(12);
        $tag        = '';
        $cipher     = openssl_encrypt($privKeyHex, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        $data = [
            'iv'        => base64_encode($iv),
            'tag'       => base64_encode($tag),
            'cipher'    => base64_encode($cipher),
            'algo'      => 'aes-256-gcm',
            'created_at'=> date('c'),
        ];
        file_put_contents(
            $this->basePath . '/' . self::KEY_FILE,
            json_encode($data, JSON_PRETTY_PRINT),
            LOCK_EX
        );
        chmod($this->basePath . '/' . self::KEY_FILE, 0600);
    }

    private function getPassphrase(): string
    {
        $pass = getenv('WALLET_PASSPHRASE');
        if (!$pass || strlen($pass) < 16) {
            throw new \RuntimeException(
                'WALLET_PASSPHRASE env var not set or too short (min 16 chars). ' .
                'Set it before generating or decrypting wallet.'
            );
        }
        return $pass;
    }

    private function ensureDir(): void
    {
        $dir = $this->basePath . '/' . self::WALLET_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
    }
}
