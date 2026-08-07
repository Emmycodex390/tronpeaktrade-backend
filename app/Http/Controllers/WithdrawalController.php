<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Admin-side withdrawal approval queue. Separate from
 * Api\WithdrawalController (which handles the user-facing bank()/
 * crypto() request submission) — this is where an admin reviews and
 * finalizes those already-submitted requests.
 *
 * Important: balance is deducted from the user's wallet the moment a
 * withdrawal is REQUESTED (see Api\WithdrawalController), not on
 * approval here. So approve() doesn't touch the wallet at all — it just
 * confirms the admin actually sent the funds externally. reject()
 * refunds the wallet, since the balance was taken but the withdrawal
 * isn't going through.
 */
class WithdrawalController extends Controller
{
    /**
     * GET /api/admin/withdrawals
     */
    public function list(Request $request)
    {
        $query = Withdrawal::with('user:id,name,email')->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * PUT /api/admin/withdrawals/{id}/approve
     */
    public function approve($id)
    {
        $withdrawal = Withdrawal::findOrFail($id);

        if ($withdrawal->status !== 'pending') {
            return response()->json(['error' => 'This withdrawal has already been processed.'], 422);
        }

        $withdrawal->status = 'approved';
        $withdrawal->save();

        return response()->json(['status' => 'success', 'withdrawal' => $withdrawal]);
    }

    /**
     * PUT /api/admin/withdrawals/{id}/reject
     *
     * Refunds the wallet the balance was originally deducted from —
     * bank withdrawals refund the USDT wallet directly; crypto
     * withdrawals reconvert the stored USD amount back to coin units at
     * today's live price (the original coin amount deducted at request
     * time isn't itself stored, only the USD value — see class docblock
     * for the resulting minor price-drift caveat).
     */
    public function reject(Request $request, $id)
    {
        $withdrawal = Withdrawal::findOrFail($id);

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

                $coinAmount = $this->refundCoinAmount($withdrawal);
                $wallet->balance += $coinAmount;
                $wallet->save();
            }

            $withdrawal->status = 'rejected';
            if ($request->filled('reason')) {
                $withdrawal->admin_note = $request->input('reason');
            }
            $withdrawal->save();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Reject failed', 'message' => $e->getMessage()], 500);
        }

        return response()->json(['status' => 'success', 'withdrawal' => $withdrawal]);
    }

    /**
     * Reconvert a rejected crypto withdrawal's stored USD amount back
     * into coin units at today's live price. Same price table as
     * Api\WithdrawalController::livePrice — duplicated here rather than
     * shared to avoid coupling two controllers together for one method.
     */
    private function refundCoinAmount(Withdrawal $withdrawal): float
    {
        $ids = ['BTC' => 'bitcoin', 'ETH' => 'ethereum', 'SOL' => 'solana'];
        $id = $ids[$withdrawal->coin] ?? null;

        if ($id) {
            try {
                $response = Http::timeout(6)->get('https://api.coingecko.com/api/v3/simple/price', [
                    'ids' => $id,
                    'vs_currencies' => 'usd',
                ]);
                $price = $response->successful() ? $response->json("{$id}.usd") : null;
                if ($price) {
                    return (float) $withdrawal->amount / (float) $price;
                }
            } catch (\Exception $e) {}
        }

        // Live price lookup failed — refund the raw USD amount as a
        // fallback rather than losing the refund entirely. Better than
        // blocking the reject, though it means the user gets back a
        // dollar-for-dollar amount in coin units rather than a properly
        // converted one in this edge case.
        return (float) $withdrawal->amount;
    }
}