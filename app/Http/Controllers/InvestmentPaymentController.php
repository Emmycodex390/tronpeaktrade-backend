<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\InvestmentPayment;
use App\Models\InvestmentPlan;
use App\Models\SubscriptionCode;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvestmentPaymentController extends Controller
{
    public function stakingPlan()
    {
        $plans = InvestmentPayment::select('plan_name', 'profit_percent', 'duration', 'min_amount')
            ->where('status', 'active')
            ->groupBy('plan_name', 'profit_percent', 'duration', 'min_amount')
            ->get()
            ->map(function ($plan) {
                return [
                    'id' => Str::uuid(),
                    'plan_name' => $plan->plan_name,
                    'min_amount' => $plan->min_amount ?? 100,
                    'profit_percent' => $plan->profit_percent,
                    'duration' => $plan->duration . ' days',
                ];
            })
            ->values();

        if ($plans->isEmpty()) {
            $plans = collect([
                [
                    'id' => 1,
                    'plan_name' => 'Basic',
                    'min_amount' => 100,
                    'profit_percent' => 10,
                    'duration' => '30 days',
                ],
                [
                    'id' => 2,
                    'plan_name' => 'Premium',
                    'min_amount' => 500,
                    'profit_percent' => 20,
                    'duration' => '60 days',
                ],
            ]);
        }

        return response()->json(['data' => $plans]);
    }
   
   public function subscribeWithCode(Request $request)
{
    $request->validate([
        'code' => 'required|string',
    ]);

    $user = Auth::user();

    $subCode = SubscriptionCode::where('code', strtoupper($request->code))
        ->where('active', true)
        ->first();

    if (!$subCode) {
        return response()->json(['error' => 'Invalid or inactive code'], 404);
    }

    if ($subCode->expires_at && now()->greaterThan($subCode->expires_at)) {
        return response()->json(['error' => 'Code expired'], 400);
    }

    if ($subCode->max_uses && $subCode->used_count >= $subCode->max_uses) {
        return response()->json(['error' => 'Code usage limit reached'], 400);
    }

    $plan = InvestmentPlan::find($subCode->plan_id);

    if (!$plan || $plan->status !== 'active') {
        return response()->json(['error' => 'Plan unavailable'], 404);
    }

    // Create active investment immediately
    $investment = InvestmentPayment::create([
        'user_id' => $user->id,
        'plan_name' => $plan->plan_name,
        'amount' => $plan->min_amount,
        'profit_percent' => $plan->profit_percent,
        'expected_profit' => ($plan->min_amount * $plan->profit_percent / 100),
        'duration' => $plan->duration,
        'status' => 'active',
        'start_date' => now(),
        'end_date' => now()->addDays($plan->duration),
        'transaction_id' => 'CODE-' . Str::uuid(),
        'selar_payment_link' => null,
    ]);

    $subCode->increment('used_count');

    return response()->json([
        'success' => true,
        'message' => 'Subscription activated successfully!',
        'data' => $investment
    ]);
}
   
    public function userInvestments(Request $request)
    {
        $user = Auth::user();

        $investments = InvestmentPlan::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $investments]);
    }

    public function createInvestment(Request $request)
{
    $request->validate([
        'plan_name' => 'required|string',
        'amount' => 'required|numeric|min:1',
    ]);

    $user = Auth::user();

    // Find plan from new table
    $plan = InvestmentPlan::where('plan_name', $request->plan_name)
        ->where('status', 'active')
        ->first();

    if (!$plan) {
        return response()->json(['error' => 'Investment plan not found or inactive'], 404);
    }

    // Validate minimum amount
    if ($request->amount < $plan->min_amount) {
        return response()->json([
            'error' => "Minimum amount for {$plan->plan_name} is \${$plan->min_amount}"
        ], 422);
    }

    // Build payment link
    if (!$plan->selar_payment_link) {
        return response()->json(['error' => 'Selar payment link not configured for this plan'], 500);
    }

    $selarLink = $plan->selar_payment_link
        . '?amount=' . $request->amount
        . '&user_id=' . $user->id
        . '&plan=' . urlencode($plan->plan_name);

    // Create investment record
    $investment = InvestmentPayment::create([
        'user_id' => $user->id,
        'plan_name' => $plan->plan_name,
        'amount' => $request->amount,
        'profit_percent' => $plan->profit_percent,
        'expected_profit' => ($request->amount * $plan->profit_percent / 100),
        'duration' => $plan->duration,
        'status' => 'pending',
        'start_date' => now(),
        'end_date' => now()->addDays($plan->duration),
        'transaction_id' => 'TXN-' . Str::uuid(),
        'selar_payment_link' => $selarLink,
    ]);

    return response()->json([
        'status' => 'success',
        'data' => $investment,
        'payment_url' => $selarLink,
        'message' => 'Investment created successfully. Continue to payment...',
    ]);
}
}