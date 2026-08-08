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

    /**
     * GET /api/withdrawals
     *
     * The current user's own withdrawal requests, each with its
     * verification status so the frontend can show a code-entry step
     * for any that have an active unconfirmed code.
     */
    public function index(Request $request)
    {
        $withdrawals = Withdrawal::where('user_id', $request->user()->id)
            ->with(['verifications' => function ($q) {
                $q->orderBy('created_at', 'desc');
            }])
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($w) {
                $pending = $w->verifications->whereNull('verified_at');
                $active = $pending->first();

                return [
                    'id' => $w->id,
                    'type' => $w->type,
                    'amount' => (float) $w->amount,
                    'coin' => $w->coin,
                    'status' => $w->status,
                    'created_at' => $w->created_at,
                    'requires_code' => $pending->count() > 0,
                    'code_sent' => $active ? (bool) $active->sent_at : false,
                    'remaining' => $pending->count(),
                    'title' => $active?->label,
                    'explanation' => $active?->message,
                ];
            });

        return response()->json(['data' => $withdrawals]);
    }

    /**
     * POST /api/withdrawals/{id}/verify-code
     *
     * Submits one code toward this withdrawal's pending verification
     * requirements. Confirming the LAST one auto-approves the
     * withdrawal immediately — no separate admin click needed.
     */
    public function verifyCode(Request $request, $id)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $withdrawal = Withdrawal::where('user_id', $request->user()->id)->findOrFail($id);

        if ($withdrawal->status !== 'pending') {
            return response()->json(['error' => 'This withdrawal is no longer pending.'], 422);
        }

        $match = \App\Models\WithdrawalVerification::where('withdrawal_id', $withdrawal->id)
            ->whereNull('verified_at')
            ->where('code', strtoupper(trim($request->code)))
            ->first();

        if (!$match) {
            return response()->json(['error' => 'Incorrect code, or it\'s already been used.'], 422);
        }

        if ($match->sent_at && now()->diffInMinutes($match->sent_at) > 30) {
            return response()->json(['error' => 'This code has expired. Ask support to resend it.'], 422);
        }

        $match->verified_at = now();
        $match->save();

        $remainingQuery = \App\Models\WithdrawalVerification::where('withdrawal_id', $withdrawal->id)
            ->whereNull('verified_at');
        $remainingCount = (clone $remainingQuery)->count();
        $next = $remainingQuery->first();

        if ($remainingCount === 0 && $withdrawal->status === 'pending') {
            $withdrawal->status = 'approved';
            $withdrawal->save();
        }

        return response()->json([
            'status' => 'success',
            'remaining' => $remainingCount,
            'title' => $next?->label,
            'explanation' => $next?->message,
            'withdrawal_status' => $withdrawal->status,
            'message' => $remainingCount > 0
                ? "Confirmed — {$remainingCount} more verification" . ($remainingCount > 1 ? 's' : '') . ' needed.'
                : 'Confirmed — your withdrawal has been approved.',
        ]);
    }

    /**
     * POST /api/withdrawals/{id}/cancel
     *
     * User-initiated cancellation — allowed any time the withdrawal is
     * still 'pending', whether or not a verification code has already
     * been sent. Refunds the wallet exactly like an admin reject would,
     * but marks the withdrawal 'cancelled' rather than 'rejected' so the
     * record shows this was the user's own choice, not an admin action.
     */
    public function cancel(Request $request, $id)
    {
        $withdrawal = Withdrawal::where('user_id', $request->user()->id)->findOrFail($id);

        if ($withdrawal->status !== 'pending') {
            return response()->json(['error' => 'This withdrawal has already been processed.'], 422);
        }

        DB::beginTransaction();
        try {
            if ($withdrawal->type === 'bank') {
                $wallet = Wallet::firstOrCreate(
                    ['user_id' => $withdrawal->user_id, 'symbol' => 'USDT', 'trading_mode' => 'crypto'],
                    ['coin' => 'USDT', 'address' => 'auto-created', 'balance' => 0]
                );
                $wallet->balance += $withdrawal->amount;
                $wallet->save();
            } else {
                $wallet = Wallet::firstOrCreate(
                    ['user_id' => $withdrawal->user_id, 'symbol' => $withdrawal->coin, 'trading_mode' => 'crypto'],
                    ['coin' => $withdrawal->coin, 'address' => 'auto-created', 'balance' => 0]
                );

                $price = $this->livePrice($withdrawal->coin);
                $coinAmount = $price ? (float) $withdrawal->amount / $price : (float) $withdrawal->amount;
                $wallet->balance += $coinAmount;
                $wallet->save();
            }

            $withdrawal->status = 'cancelled';
            $withdrawal->save();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Cancel failed', 'message' => $e->getMessage()], 500);
        }

        return response()->json(['status' => 'success', 'message' => 'Withdrawal cancelled and refunded.']);
    }
}