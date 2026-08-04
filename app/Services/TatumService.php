<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TatumService
{
    protected static function apiKey(): string
    {
        return env('TATUM_ENV') === 'mainnet'
            ? env('TATUM_API_KEY_MAINNET')
            : env('TATUM_API_KEY_TESTNET');
    }

    protected static function baseUrl(): string
    {
        return 'https://api.tatum.io/v3';
    }

    /**
     * Create wallet and address for a specific coin.
     */
    public static function createAddress(string $coin): array
    {
        $map = [
            'btc' => [
                'name' => 'Bitcoin',
                'network' => 'Bitcoin Mainnet',
                'slug' => 'bitcoin',
            ],
            'eth' => [
                'name' => 'Ethereum',
                'network' => 'Ethereum Mainnet',
                'slug' => 'ethereum',
            ],
            'sol' => [
                'name' => 'Solana',
                'network' => 'Solana Mainnet',
                'slug' => 'solana',
            ],
        ];

        $coin = strtolower($coin);
        if (!isset($map[$coin])) {
            throw new \Exception("Unsupported coin: {$coin}");
        }

        $meta = $map[$coin];
        $baseUrl = self::baseUrl();
        $apiKey = self::apiKey();

        try {
            // ✅ Handle Solana separately
            if ($meta['slug'] === 'solana') {
                $response = Http::withHeaders([
                    'x-api-key' => $apiKey,
                ])->get("{$baseUrl}/solana/wallet");

                if ($response->failed()) {
                    throw new \Exception("Failed to create Solana wallet: " . $response->body());
                }

                $data = $response->json();

                return [
                    'symbol' => strtoupper($coin),
                    'name' => $meta['name'],
                    'network' => $meta['network'],
                    'address' => $data['address'] ?? null,
                    'xpub' => null,
                    'mnemonic' => $data['mnemonic'] ?? null,
                    'privateKey' => $data['privateKey'] ?? null,
                ];
            }

            // ✅ Default flow for BTC & ETH
            $walletResponse = Http::withHeaders([
                'x-api-key' => $apiKey,
            ])->get("{$baseUrl}/{$meta['slug']}/wallet");

            if ($walletResponse->failed()) {
                throw new \Exception("Failed to create wallet for {$coin}: " . $walletResponse->body());
            }

            $walletData = $walletResponse->json();
            $xpub = $walletData['xpub'] ?? null;

            if (!$xpub) {
                throw new \Exception("Missing xpub for {$coin}: " . json_encode($walletData));
            }

            $addressResponse = Http::withHeaders([
                'x-api-key' => $apiKey,
            ])->get("{$baseUrl}/{$meta['slug']}/address/{$xpub}/0");

            if ($addressResponse->failed()) {
                throw new \Exception("Failed to generate address for {$coin}: " . $addressResponse->body());
            }

            $addressData = $addressResponse->json();

            return [
                'symbol' => strtoupper($coin),
                'name' => $meta['name'],
                'network' => $meta['network'],
                'address' => $addressData['address'] ?? null,
                'xpub' => $xpub,
                'mnemonic' => $walletData['mnemonic'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error("Tatum wallet generation failed", [
                'coin' => $coin,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}