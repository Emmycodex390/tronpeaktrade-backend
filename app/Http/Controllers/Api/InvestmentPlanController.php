<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvestmentPayment;
use App\Models\InvestmentPlan;
use Illuminate\Support\Facades\Auth; 
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

class InvestmentPlanController extends Controller
{
    // GET all plans
    public function index()
    {
        return response()->json([
            'data' => InvestmentPlan::all()
        ]);
    }

    // GET /api/investment-plans/sync — nothing seeds this table
    // otherwise. selar_payment_link is deliberately left null: that has
    // to be a real link from your actual Selar seller account, not
    // something fabricated here.
    public function sync()
    {
        $defaults = [
            ['plan_name' => 'Starter', 'min_amount' => 100, 'profit_percent' => 10, 'duration' => 30, 'status' => 'active'],
            ['plan_name' => 'Growth', 'min_amount' => 500, 'profit_percent' => 18, 'duration' => 60, 'status' => 'active'],
            ['plan_name' => 'Premium', 'min_amount' => 2000, 'profit_percent' => 30, 'duration' => 90, 'status' => 'active'],
        ];

        foreach ($defaults as $plan) {
            InvestmentPlan::firstOrCreate(['plan_name' => $plan['plan_name']], $plan);
        }

        return response()->json(['data' => InvestmentPlan::all()]);
    }

    public function userInvestments()
    {
        return response()->json([
            'data' => InvestmentPayment::where('user_id', Auth::id())->get()
        ]);
    }


    /**
     * POST /api/investments/mark-paid
     *
     * For the "insufficient balance" fallback: user sees a deposit
     * address, sends funds externally, then taps "I've paid" here.
     * Creates a pending investment — no wallet is touched (nothing to
     * deduct, since payment happened outside the app) — and it waits
     * in the same admin queue as Selar-based investments until an
     * admin confirms the deposit actually arrived and flips it active.
     */
    public function markPaidPending(Request $request)
    {
        $request->validate([
            'plan_name' => 'required|string',
            'amount' => 'required|numeric|min:1',
        ]);

        $user = Auth::user();

        $plan = InvestmentPlan::where('plan_name', $request->plan_name)
            ->where('status', 'active')
            ->first();

        if (!$plan) {
            return response()->json(['error' => 'Investment plan not found or inactive'], 404);
        }

        $duration = (int) $plan->duration;

        if ($request->amount < $plan->min_amount) {
            return response()->json([
                'error' => "Minimum amount for {$plan->plan_name} is \${$plan->min_amount}"
            ], 422);
        }

        $investment = InvestmentPayment::create([
            'user_id' => $user->id,
            'plan_name' => $plan->plan_name,
            'amount' => $request->amount,
            'profit_percent' => $plan->profit_percent,
            'expected_profit' => ($request->amount * $plan->profit_percent / 100),
            'duration' => $duration,
            'status' => 'pending',
            'start_date' => now(),
            'end_date' => now()->addDays($duration),
            'transaction_id' => 'TXN-' . Str::uuid(),
            'payment_method' => 'manual_deposit',
        ]);

        \App\Services\PushService::notifyAdmins(
            'Investment payment claimed',
            "{$user->name} says they paid \${$request->amount} for {$plan->plan_name}. Check your payment account to confirm.",
            "/admin/investments"
        );

        return response()->json([
            'status' => 'success',
            'data' => $investment,
            'message' => "We've recorded your investment — it activates once we confirm your deposit.",
        ]);
    }

    public function createInvestment(Request $request)
    {
        $request->validate([
            'plan_name' => 'required|string',
            'amount' => 'required|numeric|min:1',
            'coin' => 'nullable|string',
        ]);

        $user = Auth::user();

        // Find plan
        $plan = InvestmentPlan::where('plan_name', $request->plan_name)
            ->where('status', 'active')
            ->first();

        if (!$plan) {
            return response()->json(['error' => 'Investment plan not found or inactive'], 404);
        }

        // Cast duration safely
        $duration = (int) $plan->duration;

        if ($duration <= 0) {
            return response()->json(['error' => 'Invalid plan duration'], 422);
        }

        // Minimum amount check
        if ($request->amount < $plan->min_amount) {
            return response()->json([
                'error' => "Minimum amount for {$plan->plan_name} is \${$plan->min_amount}"
            ], 422);
        }

        // ── Payment priority: Selar first if this plan has a real link
        // configured, balance as the automatic fallback otherwise. Not
        // something the user chooses — determined purely by whether an
        // admin has actually set up Selar for this specific plan.
        if ($plan->selar_payment_link) {
            $selarLink = $plan->selar_payment_link
                . '?amount=' . $request->amount
                . '&user_id=' . $user->id
                . '&plan=' . urlencode($plan->plan_name);

            $investment = InvestmentPayment::create([
                'user_id' => $user->id,
                'plan_name' => $plan->plan_name,
                'amount' => $request->amount,
                'profit_percent' => $plan->profit_percent,
                'expected_profit' => ($request->amount * $plan->profit_percent / 100),
                'duration' => $duration,
                'status' => 'pending',
                'start_date' => now(),
                'end_date' => now()->addDays($duration),
                'transaction_id' => 'TXN-' . Str::uuid(),
                'selar_payment_link' => $selarLink,
                'payment_method' => 'selar',
            ]);

            // Process earnings ONLY if plan is active
            if ($plan->status === 'active') {
                $this->processEarnings();
            }

            return response()->json([
                'status' => 'success',
                'data' => $investment,
                'payment_url' => $selarLink,
                'message' => 'Investment created successfully. Continue to payment...',
            ]);
        }

        // ── No Selar link configured — pay with existing wallet
        // balance instead, in whichever coin was chosen (any coin the
        // user holds, converted at the live price).
        $coin = strtoupper($request->coin ?? 'USDT');

        // firstOrCreate (not firstWhere) — a brand new user only gets
        // BTC/ETH/SOL wallets at registration, never USDT, so picking
        // USDT (the default payment coin) with zero prior activity
        // would otherwise hit a genuinely-null wallet.
        $wallet = \App\Models\Wallet::firstOrCreate(
            ['user_id' => $user->id, 'symbol' => $coin, 'trading_mode' => 'crypto'],
            ['coin' => $coin, 'address' => 'auto-created', 'balance' => 0]
        );

        $requiredCoinAmount = \App\Services\PriceService::coinAmountForUsd($coin, (float) $request->amount);
        $available = $wallet->balance ?? 0;

        if (!$wallet || $available < $requiredCoinAmount) {
            return response()->json([
                'error' => 'insufficient_balance',
                'message' => "Insufficient {$coin} balance to fund this investment.",
                'coin' => $coin,
                'required' => $requiredCoinAmount,
                'available' => $available,
            ], 422);
        }

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $wallet->balance -= $requiredCoinAmount;
            $wallet->save();

            $investment = InvestmentPayment::create([
                'user_id' => $user->id,
                'plan_name' => $plan->plan_name,
                'amount' => $request->amount,
                'profit_percent' => $plan->profit_percent,
                'expected_profit' => ($request->amount * $plan->profit_percent / 100),
                'duration' => $duration,
                'status' => 'active', // balance payment is instant and needs no admin approval — this is what "activate automatically" means
                'start_date' => now(),
                'end_date' => now()->addDays($duration),
                'transaction_id' => 'TXN-' . Str::uuid(),
                'payment_method' => 'balance',
                'payment_coin' => $coin,
            ]);

            \Illuminate\Support\Facades\DB::commit();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json(['error' => 'Failed to process investment', 'message' => $e->getMessage()], 500);
        }

        return response()->json([
            'status' => 'success',
            'data' => $investment,
            'message' => 'Investment funded from your balance and is now active.',
        ]);
    }

    // Create plan
    public function store(Request $request)
    {
        $validated = $request->validate([
            'plan_name' => 'required|string',
            'min_amount' => 'required|numeric',
            'profit_percent' => 'required|numeric',
            'duration' => 'required|numeric|min:1',
            'selar_payment_link' => 'nullable|string',
            'status' => 'required|string',
        ]);

        $validated['duration'] = (int) $validated['duration'];

        $plan = InvestmentPlan::create($validated);

        return response()->json([
            'message' => 'Investment plan created successfully',
            'data' => $plan
        ], 201);
    }


    // Update plan
    public function update(Request $request, $id)
    {
        $plan = InvestmentPlan::findOrFail($id);

        $validated = $request->validate([
            'plan_name' => 'required|string',
            'min_amount' => 'required|numeric',
            'profit_percent' => 'required|numeric',
            'duration' => 'required|numeric|min:1',
            'selar_payment_link' => 'nullable|string',
            'status' => 'required|string',
        ]);

        $validated['duration'] = (int) $validated['duration'];

        $plan->update($validated);

        return response()->json([
            'message' => 'Investment plan updated successfully',
            'data' => $plan
        ]);
    }


    /**
     * POST /api/investments/{id}/withdraw
     *
     * Cash out an investment's remaining value (principal + profit not
     * yet paid via the daily drip) into any coin the user chooses —
     * instant, no admin approval needed, unlike deposits. Works for
     * 'active' investments (early exit — no penalty, just pays out
     * whatever hasn't accrued yet based on real elapsed time) and
     * 'completed' ones that still have an unpaid remainder (can happen
     * if the daily drip didn't divide the term evenly).
     */
    public function withdraw(Request $request, $id)
    {
        $request->validate([
            'coin' => 'required|string',
        ]);

        $user = Auth::user();
        $investment = InvestmentPayment::where('user_id', $user->id)->findOrFail($id);

        // Only fully matured investments are withdrawable — no early
        // exit. An investment only reaches 'completed' once the daily
        // accrual has run its full course (see processEarnings below) or
        // a prior withdraw call already settled it.
        if ($investment->status !== 'completed') {
            return response()->json(['error' => 'This investment hasn\'t matured yet.'], 422);
        }

        $totalOwed = $investment->amount + $investment->expected_profit;
        $remaining = max(0, $totalOwed - ($investment->paid_out ?? 0));

        if ($remaining <= 0) {
            return response()->json(['error' => 'Nothing left to withdraw from this investment.'], 422);
        }

        // Every unverified requirement blocks the payout — admin can add
        // a fresh one at any time (e.g. suspected compromise), which
        // immediately re-blocks withdrawal even if earlier ones were
        // already confirmed, since this always re-checks current state.
        $pending = \App\Models\InvestmentWithdrawalVerification::where('investment_payment_id', $investment->id)
            ->whereNull('verified_at')
            ->get();

        if ($pending->isEmpty()) {
            $everHadOne = \App\Models\InvestmentWithdrawalVerification::where('investment_payment_id', $investment->id)->exists();

            if (!$everHadOne) {
                // First-ever attempt on this investment — nothing to
                // verify yet, just flag it for an admin to act on.
                \App\Services\PushService::notifyAdmins(
                    'Matured investment withdrawal requested',
                    "{$user->name} wants to withdraw \${$remaining} from their matured {$investment->plan_name} plan. Send a confirmation code to release it.",
                    "/admin/investment-withdrawals"
                );

                return response()->json([
                    'requires_code' => true,
                    'code_sent' => false,
                    'error' => 'This withdrawal needs to be confirmed first — we\'ve notified our team, and you\'ll receive a confirmation code by email shortly.',
                ], 403);
            }
            // else: $pending is empty and at least one verification has
            // existed before — everything required has been confirmed,
            // fall through to actually process the withdrawal below.
        } else {
            $sentCount = $pending->whereNotNull('sent_at')->count();
            $active = $pending->first();
            return response()->json([
                'requires_code' => true,
                'code_sent' => $sentCount > 0,
                'remaining' => $pending->count(),
                'title' => $active->label,
                'explanation' => $active->message,
                'error' => $sentCount > 0
                    ? 'Enter the confirmation code we emailed you to continue.'
                    : 'This withdrawal is awaiting confirmation from our team.',
            ], 403);
        }

        $coin = strtoupper($request->coin);
        $coinAmount = \App\Services\PriceService::coinAmountForUsd($coin, (float) $remaining);

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $wallet = \App\Models\Wallet::firstOrCreate(
                ['user_id' => $user->id, 'symbol' => $coin, 'trading_mode' => 'crypto'],
                ['coin' => $coin, 'address' => 'auto-created', 'balance' => 0]
            );

            $wallet->balance += $coinAmount;
            $wallet->save();

            $investment->paid_out = $totalOwed;
            $investment->last_payout_at = now();
            $investment->save();

            \Illuminate\Support\Facades\DB::commit();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json(['error' => 'Withdrawal failed', 'message' => $e->getMessage()], 500);
        }

        return response()->json([
            'status' => 'success',
            'coin' => $coin,
            'amount_credited' => $coinAmount,
            'usd_value' => $remaining,
            'message' => "Withdrew \${$remaining} as {$coinAmount} {$coin}.",
        ]);
    }

    /**
     * POST /api/investments/{id}/verify-withdrawal-code
     *
     * Submits one code toward however many verification requirements
     * this investment currently has pending. Matches against ANY
     * unverified row for this investment — order doesn't matter, the
     * user just enters whichever code they most recently received.
     */
    public function verifyWithdrawalCode(Request $request, $id)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $user = Auth::user();
        $investment = InvestmentPayment::where('user_id', $user->id)->findOrFail($id);

        $match = \App\Models\InvestmentWithdrawalVerification::where('investment_payment_id', $investment->id)
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

        $remaining = \App\Models\InvestmentWithdrawalVerification::where('investment_payment_id', $investment->id)
            ->whereNull('verified_at');
        $remainingCount = (clone $remaining)->count();
        $next = $remaining->first();

        return response()->json([
            'status' => 'success',
            'remaining' => $remainingCount,
            'title' => $next?->label,
            'explanation' => $next?->message,
            'message' => $remainingCount > 0
                ? "Confirmed — {$remainingCount} more verification" . ($remainingCount > 1 ? 's' : '') . ' needed.'
                : 'Confirmed — you can now complete your withdrawal.',
        ]);
    }

    // PROCESS EARNINGS EVERY 24 HOURS
    public function processEarnings()
    {
        $user = Auth::user();

        $investments = InvestmentPayment::where('user_id', $user->id)
            // 'active' = balance-paid (instant) or admin-confirmed Selar
            // payments; 'pending' = still awaiting admin confirmation for
            // a Selar payment. Only these two should actually accrue —
            // completed/rejected/cancelled shouldn't. Previously this
            // only checked 'pending', which meant an investment stopped
            // earning the moment admin confirmed it as 'active'.
            ->whereIn('status', ['pending', 'active'])
            ->get();

        foreach ($investments as $inv) {

            $plan = InvestmentPlan::where('plan_name', $inv->plan_name)->first();

            if (!$plan || $plan->status !== 'active') {
                continue;
            }

            // Cast duration
            $duration = (int) $inv->duration;
            if ($duration <= 0) {
                continue; // avoid division by zero
            }

            $now = Carbon::now();
            $end = Carbon::parse($inv->end_date);

            // Completed investment
            if ($now->greaterThanOrEqualTo($end)) {
                $inv->status = 'completed';
                $inv->save();
                continue;
            }

            // Check 24-hour rule
            if ($inv->last_payout_at && Carbon::parse($inv->last_payout_at)->diffInHours($now) < 24) {
                continue;
            }

            // Profit calculation
            $totalReturn = $inv->amount + ($inv->amount * ($inv->profit_percent / 100));
            $dailyProfit = $totalReturn / $duration;

            if ($dailyProfit < 0) {
                continue; // safety
            }

            // Credit the real USDT wallet — previously wrote to
            // user.total_usdt / investment_balance, columns completely
            // disconnected from the Wallet system every other balance
            // in the app (Dashboard, Wallet page, Trading) reads from.
            // Those profits were real money that would never have shown
            // up anywhere a user could actually see it.
            $wallet = \App\Models\Wallet::firstOrCreate(
                ['user_id' => $user->id, 'symbol' => 'USDT', 'trading_mode' => 'crypto'],
                ['coin' => 'USDT', 'address' => 'investment-earnings', 'balance' => 0]
            );
            $wallet->balance += $dailyProfit;
            $wallet->save();

            // Update investment
            $inv->paid_out += $dailyProfit;
            $inv->last_payout_at = $now;
            $inv->save();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Earnings processed successfully (active plans only)'
        ]);
    }
}