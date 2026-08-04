<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class WithdrawalController extends Controller
{
    /**
     * Fetch a live USD price for a coin from CoinGecko, replacing the
     * hardcoded price table that used to live here (BTC=90000, ETH=3000,
     * SOL=120 — static numbers that would silently drift from reality).
     */
    private function livePrice(string $coin): ?float
    {
        $ids = [
            'BTC' => 'bitcoin',
            'ETH' => 'ethereum',
            'SOL' => 'solana',
        ];

        $id = $ids[$coin] ?? null;
        if (!$id) {
            return null;
        }

        try {
            $response = Http::timeout(6)->get('https://api.coingecko.com/api/v3/simple/price', [
                'ids' => $id,
                'vs_currencies' => 'usd',
            ]);

            if ($response->successful()) {
                return $response->json("{$id}.usd");
            }
        } catch (\Exception $e) {}

        return null;
    }

    /**
     * BANK WITHDRAWAL
     */
    public function bank(Request $request)
    {
        $user = $request->user();

        if (! $user->hasCompletedAllVerifications()) {
            return response()->json([
                'requires_verification' => true,
                'error' => 'You must complete all verification requirements before withdrawing.',
            ], 403);
        }

        $data = $request->validate([
            'bank_name'      => 'required|string',
            'account_number' => 'required|string',
            'account_name'   => 'required|string',
            'amount'         => 'required|numeric|min:1',
            'note'           => 'nullable|string',
        ]);

        // Withdraws from the user's USDT wallet — the closest thing to a
        // cash balance in this system. Previously this checked
        // $user->total_usdt, a column nothing in the app ever populates,
        // so every real user would fail with "insufficient balance"
        // regardless of their actual funds.
        $usdtWallet = Wallet::firstWhere([
            ['user_id', $user->id],
            ['symbol', 'USDT'],
            ['trading_mode', 'crypto'],
        ]);

        if (!$usdtWallet || $usdtWallet->balance < $data['amount']) {
            return response()->json(['error' => 'Insufficient USDT balance'], 400);
        }

        DB::beginTransaction();
        try {
            $usdtWallet->balance -= $data['amount'];
            $usdtWallet->save();

            $withdrawal = Withdrawal::create([
                'user_id'        => $user->id,
                'type'           => 'bank',
                'bank_name'      => $data['bank_name'],
                'account_number' => $data['account_number'],
                'account_name'   => $data['account_name'],
                'amount'         => $data['amount'],
                'status'         => 'pending',
                'note'           => $data['note'] ?? null,
            ]);

            DB::commit();

            return response()->json([
                'success'    => true,
                'message'    => 'Bank withdrawal submitted',
                'withdrawal' => $withdrawal,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Withdrawal failed', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * CRYPTO WITHDRAWAL
     */
    public function crypto(Request $request)
    {
        $user = $request->user();

        if (! $user->hasCompletedAllVerifications()) {
            return response()->json([
                'requires_verification' => true,
                'error' => 'You must complete all verification requirements before withdrawing.',
            ], 403);
        }

        $data = $request->validate([
            'address' => 'required|string',
            'network' => 'required|string',
            'coin'    => 'required|string|in:BTC,ETH,SOL',
            'amount'  => 'required|numeric|min:1', // USD value to withdraw
            'note'    => 'nullable|string',
        ]);

        $wallet = Wallet::firstWhere([
            ['user_id', $user->id],
            ['symbol', $data['coin']],
            ['trading_mode', 'crypto'],
        ]);

        if (!$wallet) {
            return response()->json(['error' => 'Wallet not found'], 404);
        }

        $coinPrice = $this->livePrice($data['coin']);
        if (!$coinPrice) {
            return response()->json(['error' => 'Unable to fetch a live price right now — try again shortly.'], 503);
        }

        $cryptoNeeded = $data['amount'] / $coinPrice;

        if ($wallet->balance < $cryptoNeeded) {
            return response()->json(['error' => 'Insufficient crypto balance'], 400);
        }

        DB::beginTransaction();
        try {
            $wallet->balance -= $cryptoNeeded;
            $wallet->save();

            $withdrawal = Withdrawal::create([
                'user_id' => $user->id,
                'type'    => 'crypto',
                'coin'    => $data['coin'],
                'address' => $data['address'],
                'network' => $data['network'],
                'amount'  => $data['amount'],
                'status'  => 'pending',
                'note'    => $data['note'] ?? null,
            ]);

            DB::commit();

            return response()->json([
                'success'    => true,
                'message'    => 'Crypto withdrawal submitted',
                'withdrawal' => $withdrawal,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Withdrawal failed', 'message' => $e->getMessage()], 500);
        }
    }
}
