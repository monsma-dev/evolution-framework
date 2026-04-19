<?php

declare(strict_types=1);

namespace App\Core\Evolution\Clients;

use App\Core\Evolution\Wallet\TradingWallet;

/**
 * Generates secp256k1 keypairs compatible with TradingWallet / Base trading.
 */
final class ClientWalletGenerator
{
    /**
     * @return array{priv_hex: string, address: string}
     */
    public static function generateKeypair(string $basePath): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'secp256k1',
        ]);
        if (!$key) {
            throw new \RuntimeException('openssl key generation failed');
        }
        $details = openssl_pkey_get_details($key);
        $privHex = bin2hex($details['ec']['d'] ?? '');
        if (strlen($privHex) !== 64) {
            throw new \RuntimeException('unexpected priv key length');
        }
        $tw = new TradingWallet($basePath);
        $address = $tw->deriveAddressFromPrivateKeyHex($privHex);

        return ['priv_hex' => $privHex, 'address' => $address];
    }
}
