<?php

declare(strict_types=1);

namespace App\Core\Evolution\Wallet;

/**
 * TradingWallet — Aparte EVM-wallet voor day trading.
 *
 * Gescheiden van de hoofd-AgentWallet:
 *   - Andere bestanden: trading_key.enc / trading_wallet.json
 *   - Andere passphrase env var: TRADING_WALLET_PASSPHRASE
 *
 * Zo raakt de API-budget wallet NOOIT betrokken bij trades.
 */
final class TradingWallet
{
    public const DEFAULT_NETWORK  = 'base';
    public const DEFAULT_CHAIN_ID = 8453;

    private const WALLET_DIR  = 'storage/evolution/wallet';
    private const KEY_FILE    = 'storage/evolution/wallet/trading_key.enc';
    private const WALLET_FILE = 'storage/evolution/wallet/trading_wallet.json';
    private const PASSENV     = 'TRADING_WALLET_PASSPHRASE';

    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 4));
    }

    /** Genereer nieuwe trading wallet. Geeft adres + publieke info terug. */
    public function generate(bool $force = false): array
    {
        if ($this->exists() && !$force) {
            return $this->load();
        }

        $recoveryAddr = strtolower(trim((string)(getenv('TRADING_WALLET_ADDRESS') ?: '')));
        if ($recoveryAddr !== '' && !$force) {
            throw new \RuntimeException(
                '[Recovery mode] TRADING_WALLET_ADDRESS is set — refusing to generate a new trading wallet. ' .
                'Restore storage/evolution/wallet/trading_key.enc + trading_wallet.json, or run ' .
                '`php ai_bridge.php evolve:wallet import-trading-key <hex>` with TRADING_WALLET_PASSPHRASE set.'
            );
        }

        $dir = $this->basePath . '/' . self::WALLET_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'secp256k1',
        ]);

        if (!$key) {
            throw new \RuntimeException('OpenSSL secp256k1 key generation failed.');
        }

        openssl_pkey_export($key, $privPem);
        $privKeyHex = $this->extractPrivKeyHex($privPem);
        $details    = openssl_pkey_get_details($key);
        $pubKeyHex  = $this->extractPubKeyHex($details);
        $address    = $this->deriveAddress($pubKeyHex);

        $this->encryptAndStoreKey($privKeyHex);

        $wallet = [
            'address'     => $address,
            'network'     => self::DEFAULT_NETWORK,
            'chain_id'    => self::DEFAULT_CHAIN_ID,
            'purpose'     => 'trading',
            'created_at'  => date('c'),
            'pub_key_hex' => $pubKeyHex,
        ];
        file_put_contents(
            $this->basePath . '/' . self::WALLET_FILE,
            json_encode($wallet, JSON_PRETTY_PRINT),
            LOCK_EX
        );

        return $wallet;
    }

    /** Zet trading_wallet.json network/chain_id op Base (8453) — key.enc ongewijzigd. */
    public function alignMetadataWithBaseNetwork(): void
    {
        if (!$this->exists()) {
            throw new \RuntimeException('Geen trading wallet gevonden.');
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
     * Herstel trading_wallet.json vanuit trading_key.enc (adres + pub_key uit ontsleutelde sleutel).
     *
     * @return array{address: string, previous_address: string}
     */
    public function syncWalletJsonFromDecryptedKey(): array
    {
        $keyPath = $this->basePath . '/' . self::KEY_FILE;
        if (!is_file($keyPath)) {
            throw new \RuntimeException('trading_key.enc ontbreekt.');
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
            'purpose'               => 'trading',
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

    public function load(): array
    {
        $file = $this->basePath . '/' . self::WALLET_FILE;
        if (!is_file($file)) {
            throw new \RuntimeException('Geen trading wallet gevonden. Genereer eerst: php ai_bridge.php evolve:wallet generate-trading');
        }
        return json_decode((string)file_get_contents($file), true) ?? [];
    }

    public function exists(): bool
    {
        return is_file($this->basePath . '/' . self::WALLET_FILE);
    }

    /**
     * Import a known address (no private key needed — for balance checks only).
     * Creates trading_wallet.json with the given address without generating a new keypair.
     */
    public function importAddress(string $address, bool $force = false): array
    {
        if ($this->exists() && !$force) {
            return $this->load();
        }
        $dir = $this->basePath . '/' . self::WALLET_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $wallet = [
            'address'     => $address,
            'network'     => self::DEFAULT_NETWORK,
            'chain_id'    => self::DEFAULT_CHAIN_ID,
            'purpose'     => 'trading',
            'imported'    => true,
            'created_at'  => date('c'),
            'pub_key_hex' => '',
        ];
        file_put_contents(
            $this->basePath . '/' . self::WALLET_FILE,
            json_encode($wallet, JSON_PRETTY_PRINT),
            LOCK_EX
        );
        return $wallet;
    }

    /**
     * Import private key hex, encrypt with TRADING_WALLET_PASSPHRASE, store as trading_key.enc.
     * Use this to restore the trading wallet on a new machine.
     */
    public function importPrivateKey(string $privKeyHex): void
    {
        $privKeyHex = strtolower(preg_replace('/^0x/i', '', trim($privKeyHex)));
        if ($privKeyHex === '' || strlen($privKeyHex) !== 64 || !ctype_xdigit($privKeyHex)) {
            throw new \RuntimeException('Private key must be 64 hex characters (optionally 0x-prefixed).');
        }
        $dir = $this->basePath . '/' . self::WALLET_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $this->encryptAndStoreKey($privKeyHex);
        $address = $this->deriveAddressFromPrivKey($privKeyHex);
        $key     = $this->importPrivKeyFromHex($privKeyHex);
        $details = $key ? openssl_pkey_get_details($key) : null;
        $pubHex  = is_array($details) ? $this->extractPubKeyHex($details) : '';
        $wallet  = [
            'address'     => $address,
            'network'     => self::DEFAULT_NETWORK,
            'chain_id'    => self::DEFAULT_CHAIN_ID,
            'purpose'     => 'trading',
            'created_at'  => date('c'),
            'pub_key_hex' => $pubHex,
            'imported'    => true,
        ];
        file_put_contents(
            $this->basePath . '/' . self::WALLET_FILE,
            json_encode($wallet, JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    /**
     * Safety lock: trading_wallet.json vs TRADING_WALLET_ADDRESS env, and key decrypt matches json address.
     *
     * @throws \RuntimeException
     */
    public function verifyIntegrity(): void
    {
        if (!$this->exists()) {
            throw new \RuntimeException('[Safety Lock] Geen trading wallet gevonden.');
        }
        $walletData      = $this->load();
        $walletAddress   = strtolower(trim((string)($walletData['address'] ?? '')));
        $expectedAddress = strtolower(trim((string)(getenv('TRADING_WALLET_ADDRESS') ?: '')));

        if ($expectedAddress !== '' && $walletAddress !== $expectedAddress) {
            throw new \RuntimeException(sprintf(
                '[Safety Lock] TRADING ADRES MISMATCH! trading_wallet.json=%s TRADING_WALLET_ADDRESS=%s',
                $walletAddress,
                $expectedAddress
            ));
        }

        $privKeyHex  = $this->decryptPrivateKey();
        $derivedAddr = strtolower($this->deriveAddressFromPrivKey($privKeyHex));
        if ($derivedAddr !== $walletAddress) {
            throw new \RuntimeException(sprintf(
                '[Safety Lock] TRADING KEY MISMATCH! key decrypts to %s but json has %s',
                $derivedAddr,
                $walletAddress
            ));
        }
    }

    private function deriveAddressFromPrivKey(string $privKeyHex): string
    {
        $key = $this->importPrivKeyFromHex($privKeyHex);
        if (!$key) {
            throw new \RuntimeException('Kon geen OpenSSL-sleutel maken van trading private key hex.');
        }
        $details   = openssl_pkey_get_details($key);
        $pubKeyHex = $this->extractPubKeyHex($details ?: []);

        return $this->deriveAddress($pubKeyHex);
    }

    /**
     * Public helper for multi-client wallets: derive checksummed EVM address from 64-hex secp256k1 private key.
     */
    public function deriveAddressFromPrivateKeyHex(string $privKeyHex): string
    {
        $privKeyHex = strtolower(preg_replace('/^0x/i', '', trim($privKeyHex)));
        if ($privKeyHex === '' || strlen($privKeyHex) !== 64 || !ctype_xdigit($privKeyHex)) {
            throw new \InvalidArgumentException('Private key must be 64 hex characters.');
        }

        return $this->deriveAddressFromPrivKey($privKeyHex);
    }

    /** @return \OpenSSLAsymmetricKey|false */
    private function importPrivKeyFromHex(string $hex)
    {
        $privBin = hex2bin($hex);
        if ($privBin === false || strlen($privBin) !== 32) {
            return false;
        }
        // SEC1 ECPrivateKey (RFC 5915) with secp256k1 OID — not prime256v1 (P-256).
        $der = "\x30\x2e\x02\x01\x01\x04\x20" . $privBin
             . "\xa0\x07\x06\x05\x2b\x81\x04\x00\x0a";
        $pem = "-----BEGIN EC PRIVATE KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END EC PRIVATE KEY-----\n";

        return openssl_pkey_get_private($pem);
    }

    public function decryptPrivateKey(): string
    {
        $file = $this->basePath . '/' . self::KEY_FILE;
        if (!is_file($file)) {
            throw new \RuntimeException('Geen encrypted trading key gevonden.');
        }
        $passphrase = $this->getPassphrase();
        $encData    = json_decode((string)file_get_contents($file), true);

        $key    = hash('sha256', $passphrase, true);
        $iv     = base64_decode($encData['iv']);
        $tag    = base64_decode($encData['tag']);
        $cipher = base64_decode($encData['cipher']);

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('Decrypt mislukt. Verkeerde TRADING_WALLET_PASSPHRASE?');
        }
        return $plain;
    }

    // ── Internals (same crypto as AgentWallet) ────────────────────────────

    private function deriveAddress(string $pubKeyHex): string
    {
        $rawPubBin  = hex2bin(substr($pubKeyHex, 2));
        $keccakHash = $this->keccak256($rawPubBin);
        $address    = '0x' . substr($keccakHash, -40);
        return $this->toChecksumAddress($address);
    }

    private function toChecksumAddress(string $address): string
    {
        $addr   = strtolower(substr($address, 2));
        $hash   = $this->keccak256($addr);
        $result = '0x';
        for ($i = 0; $i < 40; $i++) {
            $result .= hexdec($hash[$i]) >= 8 ? strtoupper($addr[$i]) : $addr[$i];
        }
        return $result;
    }

    private function keccak256(string $input): string
    {
        return $this->keccakHash($input, 256);
    }

    /** Pure-PHP keccak-256 (correct Ethereum variant — NOT SHA3-256, NOT BLAKE2b). */
    private function keccakHash(string $input, int $bits = 256): string
    {
        $rate     = 1088 / 8;
        $inputLen = strlen($input);
        $padLen   = $rate - ($inputLen % $rate);
        $padded   = $input . str_repeat("\x00", $padLen);
        $padded[$inputLen]                   = chr(ord($padded[$inputLen])                   | 0x01);
        $padded[strlen($padded) - 1]         = chr(ord($padded[strlen($padded) - 1])         | 0x80);

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
            $t = $state[1];
            for ($i = 0; $i < 24; $i++) {
                $j         = $PILN[$i];
                $tmp       = $state[$j];
                $state[$j] = $this->rot64($t, $ROTC[$i]);
                $t         = $tmp;
            }
            for ($y = 0; $y < 5; $y++) {
                $b   = 5 * $y;
                $row = [$state[$b], $state[$b+1], $state[$b+2], $state[$b+3], $state[$b+4]];
                for ($x = 0; $x < 5; $x++) {
                    $state[$b+$x][0] = ($row[$x][0] ^ (~$row[($x+1)%5][0] & $row[($x+2)%5][0])) & 0xFFFFFFFF;
                    $state[$b+$x][1] = ($row[$x][1] ^ (~$row[($x+1)%5][1] & $row[($x+2)%5][1])) & 0xFFFFFFFF;
                }
            }
            $state[0][0] = ($state[0][0] ^ $RC[$rnd][0]) & 0xFFFFFFFF;
            $state[0][1] = ($state[0][1] ^ $RC[$rnd][1]) & 0xFFFFFFFF;
        }
        return $state;
    }

    private function rot64(array $v, int $n): array
    {
        $n = $n % 64;
        if ($n === 0) return $v;
        if ($n < 32) {
            return [
                (($v[0] << $n) | ($v[1] >> (32 - $n))) & 0xFFFFFFFF,
                (($v[1] << $n) | ($v[0] >> (32 - $n))) & 0xFFFFFFFF,
            ];
        }
        $n -= 32;
        if ($n === 0) return [$v[1], $v[0]];
        return [
            (($v[1] << $n) | ($v[0] >> (32 - $n))) & 0xFFFFFFFF,
            (($v[0] << $n) | ($v[1] >> (32 - $n))) & 0xFFFFFFFF,
        ];
    }

    private function extractPrivKeyHex(string $pem): string
    {
        $key     = openssl_pkey_get_private($pem);
        $details = openssl_pkey_get_details($key);
        return bin2hex($details['ec']['d'] ?? '');
    }

    private function extractPubKeyHex(array $details): string
    {
        $x = str_pad(bin2hex($details['ec']['x'] ?? ''), 64, '0', STR_PAD_LEFT);
        $y = str_pad(bin2hex($details['ec']['y'] ?? ''), 64, '0', STR_PAD_LEFT);
        return '04' . $x . $y;
    }

    private function encryptAndStoreKey(string $privKeyHex): void
    {
        $passphrase = $this->getPassphrase();
        $key        = hash('sha256', $passphrase, true);
        $iv         = random_bytes(12);
        $tag        = '';
        $cipher     = openssl_encrypt($privKeyHex, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        $data = [
            'iv'         => base64_encode($iv),
            'tag'        => base64_encode($tag),
            'cipher'     => base64_encode($cipher),
            'algo'       => 'aes-256-gcm',
            'created_at' => date('c'),
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
        $pass = getenv(self::PASSENV);
        if (!$pass || strlen($pass) < 16) {
            throw new \RuntimeException(self::PASSENV . ' env var niet ingesteld of te kort (min 16 chars).');
        }
        return $pass;
    }
}
