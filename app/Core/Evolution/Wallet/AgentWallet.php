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
    /** Primary deployment: Base mainnet (same as evolution.json wallet + trading RPC). */
    public const DEFAULT_NETWORK = 'base';

    public const DEFAULT_CHAIN_ID = 8453;

    private const WALLET_DIR   = 'storage/evolution/wallet';
    private const KEY_FILE     = 'storage/evolution/wallet/key.enc';
    private const WALLET_FILE  = 'storage/evolution/wallet/wallet.json';

    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
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

        $recoveryAddr = strtolower(trim((string)(getenv('AGENT_WALLET_ADDRESS') ?: '')));
        if ($recoveryAddr !== '' && !$force) {
            throw new \RuntimeException(
                '[Recovery mode] AGENT_WALLET_ADDRESS is set — refusing to generate a new wallet. ' .
                'Restore storage/evolution/wallet/key.enc + wallet.json from backup, or run ' .
                '`php ai_bridge.php evolve:wallet import-key <hex>` with WALLET_PASSPHRASE set. ' .
                'Never run `generate` when a fixed main address is configured.'
            );
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
            'network'      => self::DEFAULT_NETWORK,
            'chain_id'     => self::DEFAULT_CHAIN_ID,
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

    /**
     * Import private key hex, encrypt with WALLET_PASSPHRASE, store as key.enc.
     * Use this to restore the agent wallet on a new machine.
     */
    public function importPrivateKey(string $privKeyHex): void
    {
        $privKeyHex = strtolower(preg_replace('/^0x/i', '', trim($privKeyHex)));
        if ($privKeyHex === '' || strlen($privKeyHex) !== 64 || !ctype_xdigit($privKeyHex)) {
            throw new \RuntimeException('Private key must be 64 hex characters (optionally 0x-prefixed).');
        }
        $this->ensureDir();
        $this->encryptAndStoreKey($privKeyHex);
        $address    = $this->deriveAddressFromPrivKey($privKeyHex);
        $key        = $this->importPrivKeyFromHex($privKeyHex);
        $details    = $key ? openssl_pkey_get_details($key) : null;
        $pubHex     = is_array($details) ? $this->extractPubKeyHex($details) : '';
        $wallet     = [
            'address'      => $address,
            'network'      => self::DEFAULT_NETWORK,
            'chain_id'     => self::DEFAULT_CHAIN_ID,
            'created_at'   => date('c'),
            'pub_key_hex'  => $pubHex,
            'imported'     => true,
        ];
        file_put_contents(
            $this->basePath . '/' . self::WALLET_FILE,
            json_encode($wallet, JSON_PRETTY_PRINT),
            LOCK_EX
        );
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

    /**
     * Zet alleen network + chain_id in wallet.json op Base (8453), zonder key.enc te wijzigen.
     * Gebruik na upgrade van oude installs die nog "ethereum"/1 hadden.
     */
    public function alignMetadataWithBaseNetwork(): void
    {
        if (!$this->exists()) {
            throw new \RuntimeException('No wallet found.');
        }
        $data = $this->load();
        $data['network']  = self::DEFAULT_NETWORK;
        $data['chain_id'] = self::DEFAULT_CHAIN_ID;
        file_put_contents(
            $this->basePath . '/' . self::WALLET_FILE,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    /**
     * Herstel wallet.json vanuit key.enc: adres + pub_key_hex komen uit de ontsleutelde sleutel.
     * Gebruik als wallet.json een verkeerd label had t.o.v. key.enc.
     *
     * @return array{address: string, previous_address: string}
     */
    public function syncWalletJsonFromDecryptedKey(): array
    {
        $keyPath = $this->basePath . '/' . self::KEY_FILE;
        if (!is_file($keyPath)) {
            throw new \RuntimeException('key.enc ontbreekt.');
        }
        $prev = $this->exists() ? $this->load() : [];
        $prevAddr = strtolower(trim((string)($prev['address'] ?? '')));

        $privKeyHex = $this->decryptPrivateKey();
        $address    = $this->deriveAddressFromPrivKey($privKeyHex);
        $key        = $this->importPrivKeyFromHex($privKeyHex);
        $details    = $key ? openssl_pkey_get_details($key) : null;
        $pubHex     = is_array($details) ? $this->extractPubKeyHex($details) : '';

        $wallet = [
            'address'               => $address,
            'network'               => self::DEFAULT_NETWORK,
            'chain_id'              => self::DEFAULT_CHAIN_ID,
            'created_at'            => $prev['created_at'] ?? date('c'),
            'pub_key_hex'           => $pubHex,
            'metadata_from_key_at'  => date('c'),
        ];
        if (!empty($prev['imported'])) {
            $wallet['imported'] = true;
        }

        file_put_contents(
            $this->basePath . '/' . self::WALLET_FILE,
            json_encode($wallet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        return ['address' => $address, 'previous_address' => $prevAddr];
    }

    /**
     * Safety Lock — controleert wallet-integriteit bij agent-opstart.
     *
     * Controles:
     *   1. wallet.json adres == AGENT_WALLET_ADDRESS env var (indien gezet)
     *   2. key.enc decrypteert naar het adres in wallet.json
     *
     * Gooit een RuntimeException als er een mismatch is.
     * Stel AGENT_WALLET_ADDRESS in .env in om de check te activeren.
     *
     * @throws \RuntimeException bij adres-mismatch of key-corruptie
     */
    public function verifyIntegrity(): void
    {
        if (!$this->exists()) {
            throw new \RuntimeException('[Safety Lock] Geen wallet gevonden. Genereer eerst: php ai_bridge.php evolve:wallet generate');
        }

        $walletData      = $this->load();
        $walletAddress   = strtolower(trim((string)($walletData['address'] ?? '')));
        $expectedAddress = strtolower(trim((string)(getenv('AGENT_WALLET_ADDRESS') ?: '')));

        // Stap 1: Vergelijk met AGENT_WALLET_ADDRESS env var
        if ($expectedAddress !== '' && $walletAddress !== $expectedAddress) {
            throw new \RuntimeException(sprintf(
                '[Safety Lock] ADRES MISMATCH! wallet.json bevat %s maar AGENT_WALLET_ADDRESS is %s. ' .
                'De agent is gestopt om te voorkomen dat er met de verkeerde wallet gewerkt wordt.',
                $walletAddress, $expectedAddress
            ));
        }

        // Stap 2: Verifieer dat key.enc naar hetzelfde adres decrypteert
        try {
            $privKeyHex  = $this->decryptPrivateKey();
            $derivedAddr = strtolower($this->deriveAddressFromPrivKey($privKeyHex));

            if ($derivedAddr !== $walletAddress) {
                throw new \RuntimeException(sprintf(
                    '[Safety Lock] KEY MISMATCH! key.enc decrypteert naar %s maar wallet.json bevat %s. ' .
                    'De sleutel en het wallet-adres komen niet overeen. Agent gestopt.',
                    $derivedAddr, $walletAddress
                ));
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('[Safety Lock] Sleutelverificatie mislukt: ' . $e->getMessage());
        }
    }

    /** Derive Ethereum address from raw private key hex (public key derivation via OpenSSL). */
    private function deriveAddressFromPrivKey(string $privKeyHex): string
    {
        $key = $this->importPrivKeyFromHex($privKeyHex);
        if (!$key) {
            throw new \RuntimeException('Kon geen OpenSSL-sleutelobject maken vanuit private key hex.');
        }
        $details   = openssl_pkey_get_details($key);
        $pubKeyHex = $this->extractPubKeyHex($details ?: []);
        return $this->deriveAddress($pubKeyHex);
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

    // ── Keccak-256 (pure-PHP, correct Ethereum variant) ───────────────────
    // Note: sodium_crypto_generichash = BLAKE2b (NOT keccak-256).
    //       PHP hash('sha3-256') = NIST SHA3 (NOT keccak-256).
    //       Ethereum uses the original keccak-256 (pre-NIST padding 0x01, not 0x06).

    private function keccak256(string $input): string
    {
        return $this->keccakHash($input, 256);
    }

    /** Pure-PHP keccak-256. Uses GMP for 64-bit arithmetic on 32-bit systems. */
    private function keccakHash(string $input, int $bits = 256): string
    {
        $rate     = 1088 / 8; // 136 bytes for keccak-256
        $inputLen = strlen($input);
        $padLen   = $rate - ($inputLen % $rate);
        $padded   = $input . str_repeat("\x00", $padLen);
        $padded[$inputLen]    = chr(ord($padded[$inputLen])   | 0x01); // keccak padding
        $padded[strlen($padded) - 1] = chr(ord($padded[strlen($padded) - 1]) | 0x80);

        // State: 25 x 64-bit lanes as pairs [lo32, hi32]
        $state = array_fill(0, 25, [0, 0]);

        for ($block = 0; $block < strlen($padded); $block += $rate) {
            for ($i = 0; $i < 17; $i++) {
                $lo = unpack('V', substr($padded, $block + $i * 8,     4))[1];
                $hi = unpack('V', substr($padded, $block + $i * 8 + 4, 4))[1];
                $state[$i][0] ^= $lo;
                $state[$i][1] ^= $hi;
            }
            $state = $this->keccakF1600($state);
        }

        $out = '';
        for ($i = 0; $i < $bits / 64; $i++) {
            $out .= pack('V', $state[$i][0]) . pack('V', $state[$i][1]);
        }
        return bin2hex($out);
    }

    /**
     * Keccak-f[1600]. State: state[x + 5*y], lanes as [lo32, hi32].
     * @param  array<int,array{int,int}> $state
     * @return array<int,array{int,int}>
     */
    private function keccakF1600(array $state): array
    {
        static $RC = [
            [0x00000001,0x00000000],[0x00008082,0x00000000],[0x0000808A,0x80000000],[0x80008000,0x80000000],
            [0x0000808B,0x00000000],[0x80000001,0x00000000],[0x80008081,0x80000000],[0x00008009,0x80000000],
            [0x0000008A,0x00000000],[0x00000088,0x00000000],[0x80008009,0x00000000],[0x8000000A,0x00000000],
            [0x8000808B,0x00000000],[0x0000008B,0x80000000],[0x00008089,0x80000000],[0x00008003,0x80000000],
            [0x00008002,0x80000000],[0x00000080,0x80000000],[0x0000800A,0x00000000],[0x8000000A,0x80000000],
            [0x80008081,0x80000000],[0x00008080,0x80000000],[0x80000001,0x00000000],[0x80008008,0x80000000],
        ];
        static $PILN = [10,7,11,17,18,3,5,16,8,21,24,4,15,23,19,13,12,2,20,14,22,9,6,1];
        static $ROTC = [1,3,6,10,15,21,28,36,45,55,2,14,27,41,56,8,25,43,62,18,39,61,20,44];

        for ($rnd = 0; $rnd < 24; $rnd++) {
            // Theta: C[x] = XOR over y of A[x+5y]
            $C = [];
            for ($x = 0; $x < 5; $x++) {
                $C[$x] = [
                    $state[$x][0]^$state[$x+5][0]^$state[$x+10][0]^$state[$x+15][0]^$state[$x+20][0],
                    $state[$x][1]^$state[$x+5][1]^$state[$x+10][1]^$state[$x+15][1]^$state[$x+20][1],
                ];
            }
            for ($x = 0; $x < 5; $x++) {
                $r = $this->rot64($C[($x+1)%5], 1);
                $d = [$C[($x+4)%5][0]^$r[0], $C[($x+4)%5][1]^$r[1]];
                for ($y = 0; $y < 5; $y++) {
                    $state[$x+5*$y][0] ^= $d[0];
                    $state[$x+5*$y][1] ^= $d[1];
                }
            }

            // Rho + Pi: in-place cyclic chain starting at A[1]
            $t = $state[1];
            for ($i = 0; $i < 24; $i++) {
                $j          = $PILN[$i];
                $tmp        = $state[$j];
                $state[$j]  = $this->rot64($t, $ROTC[$i]);
                $t          = $tmp;
            }

            // Chi: for each row y, apply in x-direction (needs row copy to avoid overwrite)
            for ($y = 0; $y < 5; $y++) {
                $b = 5 * $y;
                $row = [$state[$b], $state[$b+1], $state[$b+2], $state[$b+3], $state[$b+4]];
                for ($x = 0; $x < 5; $x++) {
                    $state[$b+$x][0] = ($row[$x][0] ^ (~$row[($x+1)%5][0] & $row[($x+2)%5][0])) & 0xFFFFFFFF;
                    $state[$b+$x][1] = ($row[$x][1] ^ (~$row[($x+1)%5][1] & $row[($x+2)%5][1])) & 0xFFFFFFFF;
                }
            }

            // Iota
            $state[0][0] = ($state[0][0] ^ $RC[$rnd][0]) & 0xFFFFFFFF;
            $state[0][1] = ($state[0][1] ^ $RC[$rnd][1]) & 0xFFFFFFFF;
        }
        return $state;
    }

    /** Left-rotate a 64-bit lane [lo32,hi32] by n bits. */
    private function rot64(array $v, int $n): array
    {
        $n = $n % 64;
        if ($n === 0) {
            return $v;
        }
        if ($n < 32) {
            return [
                (($v[0] << $n) | ($v[1] >> (32 - $n))) & 0xFFFFFFFF,
                (($v[1] << $n) | ($v[0] >> (32 - $n))) & 0xFFFFFFFF,
            ];
        }
        $n -= 32;
        if ($n === 0) {
            return [$v[1], $v[0]];
        }
        return [
            (($v[1] << $n) | ($v[0] >> (32 - $n))) & 0xFFFFFFFF,
            (($v[0] << $n) | ($v[1] >> (32 - $n))) & 0xFFFFFFFF,
        ];
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
        $privBin = hex2bin($hex);
        if ($privBin === false || strlen($privBin) !== 32) {
            throw new \RuntimeException('Invalid private key hex length.');
        }
        // SEC1 ECPrivateKey (RFC 5915) with secp256k1 OID — not prime256v1 (P-256).
        $der = "\x30\x2e\x02\x01\x01\x04\x20" . $privBin
             . "\xa0\x07\x06\x05\x2b\x81\x04\x00\x0a";
        $pem = "-----BEGIN EC PRIVATE KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END EC PRIVATE KEY-----\n";
        $key = openssl_pkey_get_private($pem);
        if (!$key) {
            throw new \RuntimeException('Kon geen OpenSSL-sleutel maken van private key hex.');
        }

        return $key;
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
