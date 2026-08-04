<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\WalletLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SwapController extends Controller
{
    private const COIN_IDS = [
        'BTC' => 'bitcoin', 'ETH' => 'ethereum', 'BNB' => 'binancecoin',
        'SOL' => 'solana', 'XRP' => 'ripple', 'ADA' => 'cardano',
        'DOGE' => 'dogecoin',
    ];

    private function livePrice(string $symbol): float
    {
        $symbol = strtoupper($symbol);
        if ($symbol === 'USDT' || $symbol === 'USD') {
            return 1.0;
        }

        $id = self::COIN_IDS[$symbol] ?? null;
        if (!$id) {
            return 0.0;
        }

        try {
            $response = Http::timeout(6)->get('https://api.coingecko.com/api/v3/simple/price', [
                'ids' => $id,
                'vs_currencies' => 'usd',
            ]);
            if ($response->successful()) {
                return (float) ($response->json("{$id}.usd") ?? 0);
            }
        } catch (\Exception $e) {}

        return 0.0;
    }

    /**
     * GET /api/swap/quote?from=BTC&to=USDT&amount=0.5
     * Live preview — no balance changes, just the conversion math.
     */
    public function quote(Request $request)
    {
        $data = $request->validate([
            'from' => 'required|string',
            'to' => 'required|string',
            'amount' => 'required|numeric|min:0',
        ]);

        $fromPrice = $this->livePrice($data['from']);
        $toPrice = $this->livePrice($data['to']);

        if ($fromPrice <= 0 || $toPrice <= 0) {
            return response()->json(['error' => 'Price unavailable for one of these coins right now.'], 422);
        }

        $usdValue = $data['amount'] * $fromPrice;
        $toAmount = $usdValue / $toPrice;

        return response()->json([
            'success' => true,
            'usd_value' => $usdValue,
            'to_amount' => $toAmount,
            'rate' => $fromPrice / $toPrice,
        ]);
    }

    /**
     * POST /api/swap
     * Actually executes the conversion between two of the user's wallets.
     */
    public function swap(Request $request)
    {
        $data = $request->validate([
            'from_coin' => 'required|string',
            'to_coin' => 'required|string|different:from_coin',
            'amount' => 'required|numeric|min:0.00000001',
        ]);

        $user = $request->user();
        $fromSymbol = strtoupper($data['from_coin']);
        $toSymbol = strtoupper($data['to_coin']);

        $fromWallet = Wallet::where('user_id', $user->id)
            ->where('symbol', $fromSymbol)
            ->where('trading_mode', 'crypto')
            ->first();

        if (!$fromWallet || $fromWallet->balance < $data['amount']) {
            return response()->json(['error' => 'Insufficient balance to swap that amount.'], 422);
        }

        $fromPrice = $this->livePrice($fromSymbol);
        $toPrice = $this->livePrice($toSymbol);

        if ($fromPrice <= 0 || $toPrice <= 0) {
            return response()->json(['error' => 'Price unavailable for one of these coins right now.'], 422);
        }

        $usdValue = $data['amount'] * $fromPrice;
        $toAmount = $usdValue / $toPrice;

        DB::transaction(function () use ($user, $fromWallet, $toSymbol, $data, $usdValue, $toAmount) {
            $fromWallet->balance -= $data['amount'];
            $fromWallet->usd_value = max(0, $fromWallet->usd_value - $usdValue);
            $fromWallet->save();

            $toWallet = Wallet::firstOrCreate(
                ['user_id' => $user->id, 'symbol' => $toSymbol, 'trading_mode' => 'crypto'],
                ['coin' => $toSymbol, 'address' => 'internal-swap', 'balance' => 0, 'usd_value' => 0]
            );
            $toWallet->balance += $toAmount;
            $toWallet->usd_value += $usdValue;
            $toWallet->save();

            WalletLog::create([
                'user_id' => $user->id, 'coin' => $fromWallet->symbol,
                'amount' => -$data['amount'], 'usd_value' => -$usdValue, 'action' => 'swap_out',
            ]);
            WalletLog::create([
                'user_id' => $user->id, 'coin' => $toWallet->symbol,
                'amount' => $toAmount, 'usd_value' => $usdValue, 'action' => 'swap_in',
            ]);
        });

        return response()->json([
            'success' => true,
            'from_symbol' => $fromSymbol,
            'to_symbol' => $toSymbol,
            'amount_sent' => $data['amount'],
            'amount_received' => $toAmount,
        ]);
    }
}
