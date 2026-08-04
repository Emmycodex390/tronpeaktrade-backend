<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Reward;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class RewardController extends Controller
{
    // List all rewards for the authenticated user
    public function index(Request $request)
    {
        $user = $request->user();

        $rewards = Reward::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rewards,
        ]);
    }

    /**
     * `amount` is stored as a loose string ("10", "$10", "10 USDT" —
     * whatever was typed when the reward was created), not a clean
     * decimal. This pulls the first real number out of it rather than
     * guessing at a format. Returns null if nothing numeric is found,
     * so callers can refuse to silently mark a reward "Completed"
     * without ever actually paying it.
     */
    private function parseAmount(?string $raw): ?float
    {
        if ($raw && preg_match('/[\d]+(\.[\d]+)?/', $raw, $matches)) {
            return (float) $matches[0];
        }
        return null;
    }

    private function creditReward(Reward $reward): array
    {
        $amount = $this->parseAmount($reward->amount);

        if ($amount === null || $amount <= 0) {
            return ['credited' => false, 'reason' => 'Could not read a valid amount for this reward'];
        }

        $wallet = Wallet::firstOrCreate(
            ['user_id' => $reward->user_id, 'symbol' => 'USDT', 'trading_mode' => 'crypto'],
            ['coin' => 'USDT', 'address' => 'reward-credit', 'balance' => 0]
        );

        $wallet->balance += $amount;
        $wallet->save();

        $reward->status = 'Completed';
        $reward->save();

        return ['credited' => true, 'amount' => $amount];
    }

    // Claim all active rewards — previously flipped every status to
    // "Completed" with zero money actually moving. Now genuinely credits
    // each one's USDT value to the user's wallet, and any reward whose
    // amount can't be safely parsed is left Active (not silently
    // dismissed) and reported back so it doesn't get lost.
    public function claimAll(Request $request)
    {
        $user = $request->user();
        $activeRewards = Reward::where('user_id', $user->id)->where('status', 'Active')->get();

        $results = [];

        DB::beginTransaction();
        try {
            foreach ($activeRewards as $reward) {
                $result = $this->creditReward($reward);
                $results[] = array_merge(['reward_id' => $reward->id, 'title' => $reward->title], $result);
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Claim failed', 'message' => $e->getMessage()], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Rewards processed',
            'results' => $results,
        ]);
    }

    // Claim individual reward
    public function claim($id, Request $request)
    {
        $user = $request->user();
        $reward = Reward::where('user_id', $user->id)->where('id', $id)->firstOrFail();

        if ($reward->status !== 'Active') {
            return response()->json(['success' => true, 'message' => 'Already claimed']);
        }

        DB::beginTransaction();
        try {
            $result = $this->creditReward($reward);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Claim failed', 'message' => $e->getMessage()], 500);
        }

        if (!$result['credited']) {
            return response()->json(['error' => $result['reason']], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Reward claimed successfully',
            'amount' => $result['amount'],
        ]);
    }
}
