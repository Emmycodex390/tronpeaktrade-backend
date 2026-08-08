<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StakingPlan;
use App\Models\UserStake;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserStakeController extends Controller
{
    /**
     * GET /api/stakes
     */
    public function index(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => UserStake::with('plan')
                ->where('user_id', $request->user()->id)
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    /**
     * POST /api/stakes/subscribe (and /api/stakes, kept as an alias below)
     *
     * Deducts the staked amount from the user's wallet immediately and
     * opens a real UserStake record — the APY and duration are
     * snapshotted from the plan at subscribe time so a later plan change
     * doesn't retroactively affect an existing stake.
     */
    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'staking_plan_id' => 'required|exists:staking_plans,id',
            'amount' => 'required|numeric|min:0.00000001',
            'pay_coin' => 'nullable|string',
        ]);

        $plan = StakingPlan::findOrFail($data['staking_plan_id']);

        if (!$plan->active) {
            return response()->json(['error' => 'This staking plan is no longer available'], 422);
        }

        if ($data['amount'] < $plan->min_amount) {
            return response()->json(['error' => "Minimum stake for this plan is {$plan->min_amount} {$plan->coin}"], 422);
        }

        if ($plan->max_amount && $data['amount'] > $plan->max_amount) {
            return response()->json(['error' => "Maximum stake for this plan is {$plan->max_amount} {$plan->coin}"], 422);
        }

        // Which coin's wallet actually gets debited — defaults to the
        // plan's own coin (old behavior), but a user can pay with any
        // coin they hold a balance in; the amount (given in the plan's
        // coin) gets converted to the payment coin at the live price.
        $payCoin = strtoupper($request->pay_coin ?? $plan->coin);

        $wallet = Wallet::firstOrCreate(
            ['user_id' => $request->user()->id, 'symbol' => $payCoin, 'trading_mode' => 'crypto'],
            ['coin' => $payCoin, 'address' => 'auto-created', 'balance' => 0]
        );

        $requiredPayCoinAmount = $payCoin === strtoupper($plan->coin)
            ? (float) $data['amount']
            : \App\Services\PriceService::coinAmountForUsd(
                $payCoin,
                \App\Services\PriceService::usdValueOf($plan->coin, (float) $data['amount'])
            );

        $available = $wallet->balance ?? 0;

        if (!$wallet || $available < $requiredPayCoinAmount) {
            return response()->json([
                'error' => 'insufficient_balance',
                'message' => "Insufficient {$payCoin} balance to fund this stake.",
                'coin' => $payCoin,
                'required' => $requiredPayCoinAmount,
                'available' => $available,
            ], 422);
        }

        DB::beginTransaction();
        try {
            $wallet->balance -= $requiredPayCoinAmount;
            $wallet->save();

            $stake = UserStake::create([
                'user_id' => $request->user()->id,
                'staking_plan_id' => $plan->id,
                'coin' => $plan->coin,
                'amount' => $data['amount'],
                'apy' => $plan->apy,
                'duration_days' => $plan->duration_days,
                'started_at' => now(),
                'ends_at' => $plan->duration_days > 0 ? now()->addDays($plan->duration_days) : null,
                'status' => 'active',
            ]);

            DB::commit();

            return response()->json(['success' => true, 'stake' => $stake]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Staking failed', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/stakes — kept as an alias of subscribe() since routes/api.php
     * registers both and nothing should 404 depending on which one a client calls.
     */
    public function store(Request $request)
    {
        return $this->subscribe($request);
    }

    /**
     * POST /api/stakes/claim
     *
     * Real interest accrual: simple daily rate derived from the stake's
     * own APY, applied to the actual elapsed days since the last claim
     * (or since starting, if never claimed). Not a fabricated number.
     * Accepts an optional `stake_id` to claim just one; otherwise claims
     * every eligible active stake for the user.
     */
    public function claim(Request $request)
    {
        $data = $request->validate([
            'stake_id' => 'nullable|exists:user_stakes,id',
        ]);

        $query = UserStake::where('user_id', $request->user()->id)->where('status', 'active');
        if (!empty($data['stake_id'])) {
            $query->where('id', $data['stake_id']);
        }
        $stakes = $query->get();

        $claimed = [];

        DB::beginTransaction();
        try {
            foreach ($stakes as $stake) {
                $since = $stake->last_claimed_at ?? $stake->started_at;
                $elapsedDays = $since->diffInSeconds(now()) / 86400;

                $reward = $stake->amount * ($stake->apy / 100) * ($elapsedDays / 365);

                $matured = $stake->ends_at && now()->greaterThanOrEqualTo($stake->ends_at);

                if ($reward <= 0 && !$matured) {
                    continue;
                }

                $wallet = Wallet::firstWhere([
                    ['user_id', $stake->user_id],
                    ['symbol', $stake->coin],
                    ['trading_mode', 'crypto'],
                ]);

                if (!$wallet) {
                    continue;
                }

                $credited = $reward;
                if ($matured) {
                    $credited += $stake->amount; // return principal at maturity
                    $stake->status = 'completed';
                }

                $wallet->balance += $credited;
                $wallet->save();

                $stake->total_claimed += $reward;
                $stake->last_claimed_at = now();
                $stake->save();

                $claimed[] = [
                    'stake_id' => $stake->id,
                    'coin' => $stake->coin,
                    'reward' => $reward,
                    'principal_returned' => $matured ? $stake->amount : 0,
                ];
            }

            DB::commit();

            return response()->json(['success' => true, 'claimed' => $claimed]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Claim failed', 'message' => $e->getMessage()], 500);
        }
    }
}