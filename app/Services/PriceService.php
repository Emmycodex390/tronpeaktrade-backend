<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

/**
 * Converts between USD (the currency investment/staking plans are
 * denominated in) and whatever coin a user's wallet balance happens to
 * be in — this is what lets someone pay for a plan "with their
 * available balance on any coin" instead of only the plan's own coin.
 */
class PriceService
{
    private const COINGECKO_IDS = [
        'BTC' => 'bitcoin',
        'ETH' => 'ethereum',
        'SOL' => 'solana',
    ];

    /**
     * USD price of 1 unit of $coin. USDT (and anything else not in the
     * map) is treated as 1:1 with USD — true for USDT in practice, and
     * a safe fallback for any coin we don't have a live price for.
     */
    public static function usdPriceOf(string $coin): float
    {
        $coin = strtoupper($coin);
        if ($coin === 'USDT' || $coin === 'USD') {
            return 1.0;
        }

        $geckoId = self::COINGECKO_IDS[$coin] ?? null;
        if (!$geckoId) {
            return 1.0;
        }

        $cacheKey = "price-usd-{$geckoId}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (float) $cached;
        }

        try {
            $resp = Http::timeout(6)
                ->withHeaders(array_filter(['x-cg-demo-api-key' => config('services.coingecko.key')]))
                ->get('https://api.coingecko.com/api/v3/simple/price', [
                    'ids' => $geckoId,
                    'vs_currencies' => 'usd',
                ]);

            if ($resp->successful()) {
                $price = (float) $resp->json("{$geckoId}.usd");
                if ($price > 0) {
                    Cache::put($cacheKey, $price, 60);
                    Cache::put($cacheKey . '-stale', $price, 3600);
                    return $price;
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('PriceService fetch failed', ['coin' => $coin, 'message' => $e->getMessage()]);
        }

        $stale = Cache::get($cacheKey . '-stale');
        return $stale !== null ? (float) $stale : 1.0;
    }

    /** How much of $coin equals $usdAmount right now. */
    public static function coinAmountForUsd(string $coin, float $usdAmount): float
    {
        $price = self::usdPriceOf($coin);
        return $price > 0 ? $usdAmount / $price : 0.0;
    }

    /** USD value of $coinAmount units of $coin right now. */
    public static function usdValueOf(string $coin, float $coinAmount): float
    {
        return $coinAmount * self::usdPriceOf($coin);
    }
}
