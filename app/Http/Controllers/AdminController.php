<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\VerificationCode;
use App\Models\UserVerificationEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLog;
use App\Models\AiInvestment;
use App\Models\InvestmentPlan;
use App\Models\InvestmentPayment;
use App\Models\FuturesPosition;
use App\Models\FuturesBalance;
use App\Models\Order;
use App\Models\P2PVendor;
use App\Models\UserKyc; // FIXED: Correct model name
use App\Models\SubscriptionCode;
use App\Models\Deposit;
use App\Models\Withdrawal;
use App\Models\ApiKey;
use App\Models\SecurityLog;
use App\Models\Setting;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class AdminController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', 'admin']);
    }

    // =========================
    // USERS
    // =========================
    public function listUsers()
    {
        return response()->json(User::with('wallets')->get());
    }

    public function getUser($id)
    {
        return response()->json(
            User::with(['wallets', 'aiInvestments', 'futuresPositions'])->findOrFail($id)
        );
    }

    public function updateUser(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'phone' => 'sometimes|string',
            'country' => 'sometimes|string',
            'address' => 'sometimes|nullable|string',
            'password' => 'sometimes|string|min:6',
            'status' => 'sometimes|in:active,inactive,banned',
        ]);

        $user->fill($request->only('name', 'email', 'phone', 'country', 'address', 'status'));

        if ($request->filled('password')) {
            $user->password = \Illuminate\Support\Facades\Hash::make($request->password);
        }

        $user->save();

        return response()->json($user);
    }

    public function deleteUser($id)
    {
        User::findOrFail($id)->delete();
        return response()->json(['message' => 'User deleted']);
    }

    // Admin "login as user" — swaps the current session's authenticated
    // user, remembering the admin's own id so they can return to it.
    // Session-based (matches how the rest of the app authenticates via
    // Sanctum SPA cookies), not a separate token system.
    public function loginAsUser(Request $request, $id)
    {
        $admin = $request->user();
        $target = User::findOrFail($id);

        if ($target->role === 'admin') {
            return response()->json(['error' => 'Cannot impersonate another admin'], 422);
        }

        session(['impersonator_id' => $admin->id]);
        \Illuminate\Support\Facades\Auth::guard('web')->login($target);
        $request->session()->regenerate();

        return response()->json(['success' => true, 'user' => $target]);
    }

    /**
     * Live USD price for a coin, used to compute a real usd_value when
     * admin credits a wallet — previously usd_value only updated if the
     * request explicitly sent one (it never did from the actual form),
     * so every admin-credited balance silently showed $0.00 everywhere
     * the app displays USD value (Dashboard, Wallet page, etc.) despite
     * having a real coin balance.
     */
    private function liveCoinPrice(string $symbol): float
    {
        $symbol = strtoupper($symbol);
        if ($symbol === 'USDT' || $symbol === 'USD') {
            return 1.0;
        }

        $ids = [
            'BTC' => 'bitcoin', 'ETH' => 'ethereum', 'BNB' => 'binancecoin',
            'SOL' => 'solana', 'XRP' => 'ripple', 'ADA' => 'cardano',
            'DOGE' => 'dogecoin',
        ];
        $id = $ids[$symbol] ?? null;
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
     * POST /api/admin/wallets/recalculate
     *
     * One-time correction for wallets whose usd_value fell out of sync
     * with their balance (e.g. any wallet credited before the live-price
     * fix above existed). Recomputes usd_value from balance × live price
     * for every wallet. Real prices, not fabricated adjustments.
     */
    public function recalculateWalletValues()
    {
        $wallets = Wallet::all();
        $updated = 0;
        $skipped = 0;

        foreach ($wallets as $wallet) {
            $price = $this->liveCoinPrice($wallet->symbol);
            if ($price <= 0) {
                $skipped++;
                continue;
            }
            $wallet->usd_value = $wallet->balance * $price;
            $wallet->save();
            $updated++;
        }

        return response()->json([
            'success' => true,
            'updated' => $updated,
            'skipped' => $skipped,
            'total' => $wallets->count(),
        ]);
    }

    /**
     * PUT /api/admin/wallets/{walletId}
     *
     * Direct edit of an existing wallet's exact balance — distinct from
     * fundUserWallet/adjustWallet, which only add to the current balance.
     * This overwrites it outright.
     */
    public function updateWalletBalance(Request $request, $walletId)
    {
        $wallet = Wallet::findOrFail($walletId);

        $data = $request->validate([
            'balance' => 'required|numeric|min:0',
            'usd_value' => 'nullable|numeric|min:0',
        ]);

        $wallet->balance = $data['balance'];
        $wallet->usd_value = $data['usd_value'] ?? ($data['balance'] * $this->liveCoinPrice($wallet->symbol));
        $wallet->save();

        WalletLog::create([
            'user_id' => $wallet->user_id,
            'coin' => $wallet->symbol,
            'amount' => $data['balance'],
            'usd_value' => $wallet->usd_value,
            'action' => 'admin_balance_edit',
        ]);

        return response()->json(['success' => true, 'data' => $wallet]);
    }

    public function fundUserWallet(Request $request, $id)
    {
        $request->validate([
            'coin' => 'required|string',
            'amount' => 'required|numeric',
            'usd_amount' => 'nullable|numeric',
            'trading_mode' => 'nullable|string',
        ]);

        $mode = $request->trading_mode ?? 'crypto';

        // Wallets are looked up by 'symbol' + 'trading_mode' everywhere
        // else in the app — this used 'coin', a column that doesn't
        // exist on the wallets table at all, so this would have thrown
        // a SQL error the instant an admin tried to credit anyone.
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $id, 'symbol' => strtoupper($request->coin), 'trading_mode' => $mode],
            ['coin' => strtoupper($request->coin), 'address' => 'admin-credited', 'balance' => 0, 'usd_value' => 0]
        );

        // Use the explicit usd_amount if one was actually sent, otherwise
        // compute it from a live price so this is never silently $0.
        $usdCredited = $request->usd_amount ?? ($request->amount * $this->liveCoinPrice($request->coin));

        $wallet->balance += $request->amount;
        $wallet->usd_value += $usdCredited;
        $wallet->save();

        WalletLog::create([
            'user_id' => $id,
            'coin' => $wallet->symbol,
            'amount' => $request->amount,
            'usd_value' => $usdCredited,
            'action' => 'admin_funding',
        ]);

        return response()->json($wallet);
    } 
    
    public function createSubscriptionCode(Request $request)
{
    $request->validate([
        'plan_id' => 'required|exists:investment_plans,id',
        'max_uses' => 'nullable|integer|min:1',
        'expires_at' => 'nullable|date|after:now',
    ]);

    $code = SubscriptionCode::create([
        'code' => strtoupper(Str::random(8)),
        'plan_id' => $request->plan_id,
        'max_uses' => $request->max_uses,
        'expires_at' => $request->expires_at,
    ]);

    return response()->json([
        'success' => true,
        'data' => $code
    ]);
} 

public function listSubscriptionCodes()
{
    return response()->json(SubscriptionCode::with('plan')->get());
}

public function updateSubscriptionCode(Request $request, $id)
{
    $code = SubscriptionCode::findOrFail($id);

    $data = $request->validate([
        'plan_id' => 'sometimes|exists:investment_plans,id',
        'max_uses' => 'nullable|integer|min:1',
        'expires_at' => 'nullable|date',
        'active' => 'sometimes|boolean',
    ]);

    $code->update($data);

    return response()->json(['success' => true, 'data' => $code]);
}

public function toggleSubscriptionCode($id)
{
    $code = SubscriptionCode::findOrFail($id);
    $code->active = !$code->active;
    $code->save();

    return response()->json([
        'success' => true,
        'data' => $code,
        'message' => 'Subscription code status updated',
    ]);
}

public function deleteSubscriptionCode($id)
{
    SubscriptionCode::destroy($id);
    return response()->json([
        'success' => true,
        'message' => 'Subscription code deleted',
    ]);
}



    // ============================================================
    // INVESTMENT PLANS
    // ============================================================
    public function listPlans()
    {
        return response()->json(InvestmentPlan::all());
    }

    public function getPlan($id)
    {
        return response()->json(InvestmentPlan::findOrFail($id));
    }

    public function createPlan(Request $request)
    {
        $request->validate([
            'plan_name' => 'required|string|unique:investment_plans,plan_name',
            'min_amount' => 'required|numeric|min:0',
            'profit_percent' => 'required|numeric|min:0',
            'duration' => 'required|integer|min:1',
            'selar_product_link' => 'nullable|url',
            'status' => 'nullable|in:active,inactive',
        ]);

        $plan = InvestmentPlan::create([
            'plan_name' => $request->plan_name,
            'min_amount' => $request->min_amount,
            'profit_percent' => $request->profit_percent,
            'duration' => $request->duration,
            'selar_payment_link' => $request->selar_product_link,
            'status' => $request->status ?? 'active',
        ]);

        return response()->json([
            'message' => 'Plan created successfully',
            'data' => $plan,
        ]);
    }

    public function updatePlan(Request $request, $id)
    {
        $plan = InvestmentPlan::findOrFail($id);

        $request->validate([
            'plan_name' => 'sometimes|string|unique:investment_plans,plan_name,' . $plan->id,
            'min_amount' => 'sometimes|numeric|min:0',
            'profit_percent' => 'sometimes|numeric|min:0',
            'duration' => 'sometimes|integer|min:1',
            'selar_product_link' => 'nullable|url',
            'status' => 'sometimes|in:active,inactive',
        ]);

        $plan->update([
            'plan_name' => $request->plan_name ?? $plan->plan_name,
            'min_amount' => $request->min_amount ?? $plan->min_amount,
            'profit_percent' => $request->profit_percent ?? $plan->profit_percent,
            'duration' => $request->duration ?? $plan->duration,
            'selar_payment_link' => $request->selar_product_link ?? $plan->selar_payment_link,
            'status' => $request->status ?? $plan->status,
        ]);

        return response()->json([
            'message' => 'Plan updated successfully',
            'data' => $plan->fresh(),
        ]);
    }

    public function deletePlan($id)
    {
        InvestmentPlan::destroy($id);
        return response()->json(['message' => 'Plan deleted successfully']);
    }

    // ============================================================
    // INVESTMENT PAYMENTS
    // ============================================================
public function listInvestmentPayments()
    {
        return response()->json(
            InvestmentPayment::with('user')->orderBy('id', 'desc')->get()
        );
    }

    public function getInvestmentPayment($id)
    {
        return response()->json(
            InvestmentPayment::with('user')->findOrFail($id)
        );
    }

    // Staking has no admin approval step (balance is deducted instantly
    // at subscribe time), so this is read-only — just visibility into
    // what's out there, for support/stats purposes.
    public function listUserStakes()
    {
        return response()->json(
            \App\Models\UserStake::with(['user:id,name,email', 'plan'])
                ->orderByDesc('created_at')
                ->get()
        );
    }

    public function pendingInvestments()
    {
        return response()->json([
            'data' => InvestmentPayment::with('user')
                ->where('status', 'pending')
                ->orderBy('id', 'desc')
                ->get()
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,active,rejected,cancelled,completed'
        ]);

        $inv = InvestmentPayment::findOrFail($id);

        $inv->status = $request->status;

        if ($request->status === 'active' && !$inv->start_date) {
            $inv->start_date = now();
        }

        if ($request->status === 'completed' && !$inv->end_date) {
            $inv->end_date = now();
        }

        $inv->save();

        return response()->json([
            'success' => true,
            'message' => 'Investment status updated!',
            'data' => $inv
        ]);
    }

    /**
     * Adjust a specific investment's profit up or down — for when
     * something needs manual correction (a technical issue, a
     * goodwill gesture, etc). Sets expected_profit directly, which is
     * what drives the daily accrual rate going forward
     * ((amount+expected_profit)/duration) — so this changes future
     * payouts, not just a cosmetic number.
     */
    public function adjustInvestmentProfit(Request $request, $id)
    {
        $request->validate([
            'expected_profit' => 'required|numeric|min:0',
            'reason' => 'nullable|string|max:500',
        ]);

        $inv = InvestmentPayment::findOrFail($id);
        $inv->expected_profit = $request->expected_profit;
        if ($request->filled('reason')) {
            $inv->admin_note = $request->reason;
        }
        $inv->save();

        return response()->json(['success' => true, 'message' => 'Profit adjusted.', 'data' => $inv]);
    }

    /**
     * Same idea for staking — adjusts the APY a specific stake earns
     * at, since staking rewards are computed live from apy rather than
     * a stored profit figure.
     */
    public function adjustStakeApy(Request $request, $id)
    {
        $request->validate([
            'apy' => 'required|numeric|min:0',
            'reason' => 'nullable|string|max:500',
        ]);

        $stake = \App\Models\UserStake::findOrFail($id);
        $stake->apy = $request->apy;
        if ($request->filled('reason')) {
            $stake->admin_note = $request->reason;
        }
        $stake->save();

        return response()->json(['success' => true, 'message' => 'APY adjusted.', 'data' => $stake]);
    }

    public function createInvestmentPayment(Request $request)
    {
        $request->validate([
            'auto_assign' => 'required|in:none,all,selected',
            'selected_users' => 'nullable|array',
            'selected_users.*' => 'exists:users,id',
            'user_id' => 'required_if:auto_assign,none|exists:users,id',

            'plan_id' => 'nullable|exists:investment_plans,id',
            'plan_name' => 'nullable|string',

            'amount' => 'required|numeric|min:0',
            'profit_percent' => 'nullable|numeric|min:0',
            'expected_profit' => 'nullable|numeric|min:0',
            'duration' => 'nullable|integer|min:1',
            'status' => 'required|string',

            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',

            'transaction_id' => 'nullable|string|unique:investment_payments,transaction_id',
            'selar_payment_link' => 'nullable|url',
        ]);

        // Plan logic
        $plan = null;
        if ($request->filled('plan_id')) {
            $plan = InvestmentPlan::find($request->plan_id);

            if (!$plan || $plan->status !== 'active') {
                return response()->json(['error' => 'Plan not found or inactive'], 404);
            }

            if ($request->amount < $plan->min_amount) {
                return response()->json([
                    'error' => "Minimum amount for {$plan->plan_name} is {$plan->min_amount}",
                ], 422);
            }

            $profit_percent = $request->profit_percent ?? $plan->profit_percent;
            $duration = $request->duration ?? $plan->duration;
            $selarLink = $request->selar_payment_link ?? $plan->selar_payment_link;
            $plan_name = $plan->plan_name;

        } else {
            if (!$request->filled('plan_name')) {
                return response()->json(['error' => 'Either plan_id or plan_name required'], 422);
            }

            $profit_percent = $request->profit_percent;
            $duration = $request->duration;
            $selarLink = $request->selar_payment_link;
            $plan_name = $request->plan_name;
        }

        $genId = fn() => 'AUTO-' . strtoupper(uniqid());

        $makeRecord = function ($uid) use ($request, $plan_name, $profit_percent, $duration, $selarLink, $genId) {
            $expected_profit = $request->expected_profit ??
                ($request->amount * $profit_percent / 100);

            return [
                'user_id' => $uid,
                'plan_name' => $plan_name,
                'amount' => $request->amount,
                'profit_percent' => $profit_percent,
                'expected_profit' => $expected_profit,
                'duration' => $duration,
                'status' => $request->status,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'transaction_id' => $genId(),
                'selar_payment_link' => $selarLink,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        };

        if ($request->auto_assign === 'all') {
            User::chunk(200, function ($users) use ($makeRecord) {
                $batch = [];
                foreach ($users as $user) $batch[] = $makeRecord($user->id);
                if ($batch) InvestmentPayment::insert($batch);
            });

            return response()->json(['message' => 'Created for all users']);
        }

        if ($request->auto_assign === 'selected') {
            $batch = [];
            foreach ($request->selected_users as $uid) $batch[] = $makeRecord($uid);
            InvestmentPayment::insert($batch);

            return response()->json(['message' => 'Created for selected users']);
        }

        // Single user
        $tx = $request->transaction_id ?? $genId();

        $payment = InvestmentPayment::create([
            'user_id' => $request->user_id,
            'plan_name' => $plan_name,
            'amount' => $request->amount,
            'profit_percent' => $profit_percent,
            'expected_profit' => $request->expected_profit ??
                ($request->amount * $profit_percent / 100),
            'duration' => $duration,
            'status' => $request->status,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'transaction_id' => $tx,
            'selar_payment_link' => $selarLink,
        ]);

        return response()->json($payment);
    }

    public function updateInvestmentPayment(Request $request, $id)
    {
        $payment = InvestmentPayment::findOrFail($id);

        $request->validate([
            'plan_name' => 'sometimes|string',
            'amount' => 'sometimes|numeric|min:0',
            'profit_percent' => 'sometimes|numeric|min:0',
            'expected_profit' => 'sometimes|numeric|min:0',
            'duration' => 'sometimes|integer|min:1',
            'status' => 'sometimes|string',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'transaction_id' =>
                'sometimes|string|unique:investment_payments,transaction_id,' . $payment->id,
            'selar_payment_link' => 'nullable|url',
        ]);

        $payment->update($request->all());

        return response()->json($payment);
    }

    public function deleteInvestmentPayment($id)
    {
        InvestmentPayment::destroy($id);
        return response()->json(['message' => 'Investment payment deleted']);
    }

    // =========================
    // AI INVESTMENTS
    // =========================
    // =========================
    // STAKING PLANS
    // =========================
    public function listStakingPlans()
    {
        return response()->json(\App\Models\StakingPlan::all());
    }

    public function createStakingPlan(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'coin' => 'required|string',
            'apy' => 'required|numeric|min:0',
            'duration_days' => 'required|integer|min:0',
            'min_amount' => 'required|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'active' => 'nullable|boolean',
        ]);

        $plan = \App\Models\StakingPlan::create(array_merge($data, [
            'active' => $data['active'] ?? true,
        ]));

        return response()->json(['success' => true, 'data' => $plan]);
    }

    public function updateStakingPlan(Request $request, $id)
    {
        $plan = \App\Models\StakingPlan::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string',
            'coin' => 'sometimes|string',
            'apy' => 'sometimes|numeric|min:0',
            'duration_days' => 'sometimes|integer|min:0',
            'min_amount' => 'sometimes|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'active' => 'sometimes|boolean',
        ]);

        $plan->update($data);

        return response()->json(['success' => true, 'data' => $plan]);
    }

    public function deleteStakingPlan($id)
    {
        \App\Models\StakingPlan::destroy($id);
        return response()->json(['message' => 'Staking plan deleted']);
    }

    public function listInvestmentsPlan()
    {
        return response()->json(AiInvestment::with('user')->get());
    }

    public function getInvestmentPlan($id)
    {
        return response()->json(AiInvestment::with('user')->findOrFail($id));
    }

    public function createInvestmentPlan(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'plan_id' => 'required|exists:investment_plans,id',
            'amount' => 'required|numeric|min:0',
        ]);

        $plan = InvestmentPlan::findOrFail($request->plan_id);

        $daily = $plan->daily_return ?? ($plan->profit_percent / $plan->duration);
        $durationDays = $plan->duration_days ?? $plan->duration;

        $expected = ($request->amount * $daily / 100) * $durationDays;

        $invest = AiInvestment::create([
            'user_id' => $request->user_id,
            'plan_name' => $plan->plan_name,
            'amount' => $request->amount,
            'daily_return' => $daily,
            'duration_days' => $durationDays,
            'expected_profit' => $expected,
            'earned_profit' => 0,
            'status' => 'active',
            'start_date' => now(),
            'end_date' => now()->addDays($durationDays),
            'transaction_id' => 'ADMIN-' . $request->user_id . '-' . time(),
        ]);

        return response()->json($invest);
    }

    public function updateInvestmentPlan(Request $request, $id)
    {
        $investment = AiInvestment::findOrFail($id);
        $investment->update($request->all());
        return response()->json($investment);
    }

    public function deleteInvestmentPlan($id)
    {
        AiInvestment::destroy($id);
        return response()->json(['message' => 'Investment deleted']);
    }

    // =========================
    // FUTURES
    // =========================
    public function listFuturesPositions()
    {
        return response()->json(FuturesPosition::with('user')->get());
    }

    public function listFuturesBalances()
    {
        return response()->json(FuturesBalance::with('user')->get());
    }

    public function updateFuturesPosition(Request $request, $id)
    {
        $pos = FuturesPosition::findOrFail($id);
        $pos->update($request->all());
        return response()->json($pos);
    }

    public function deleteFuturesPosition($id)
    {
        FuturesPosition::destroy($id);
        return response()->json(['message' => 'Futures position deleted']);
    }

    // =========================
    // ORDERS
    // =========================
    public function listOrders()
    {
        return response()->json(Order::with('user')->get());
    }

    public function updateOrder(Request $request, $id)
    {
        $order = Order::findOrFail($id);
        $order->update($request->all());
        return response()->json($order);
    }

    public function deleteOrder($id)
    {
        Order::destroy($id);
        return response()->json(['message' => 'Order deleted']);
    }

    public function dashboardStats()
    {
        return response()->json([
            'users' => [
                'total' => User::count(),
                'active' => User::where('id_status', 'verified')->count(),
            ],
            'balances' => [
                'wallets' => Wallet::sum('usd_value'),
                'ai_investments' => AiInvestment::sum('amount'),
                'investments' => InvestmentPayment::sum('amount'),
                'futures' => Wallet::where('trading_mode', 'futures')->sum('margin'),
            ],
            'counts' => [
                'orders' => Order::count(),
                'vendors' => P2PVendor::count(),
            ],
        ]);
    }

    // =========================
    // P2P VENDORS
    // =========================
    public function createVendor(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'currency' => 'required|string',
            'price' => 'required|numeric|min:0',
            'min_limit' => 'required|numeric|min:0',
            'max_limit' => 'required|numeric|min:0',
            'payment_methods' => 'nullable|array',
            'verified' => 'nullable|boolean',
            'online' => 'nullable|boolean',
        ]);

        $vendor = P2PVendor::create(array_merge($data, [
            'payment_methods' => $data['payment_methods'] ?? [],
            'quantity' => $data['quantity'] ?? 0,
            'trades' => 0,
            'completion' => 100,
            'verified' => $data['verified'] ?? false,
            'online' => $data['online'] ?? true,
        ]));

        return response()->json(['success' => true, 'data' => $vendor]);
    }

    public function listVendors()
    {
        return response()->json(P2PVendor::all());
    }

    public function updateVendor(Request $request, $id)
    {
        $vendor = P2PVendor::findOrFail($id);
        $vendor->update($request->all());
        return response()->json($vendor);
    }

    public function deleteVendor($id)
    {
        P2PVendor::destroy($id);
        return response()->json(['message' => 'Vendor deleted']);
    }

    // =========================
    // WALLET ADJUSTMENT
    // =========================
    public function adjustWallet(Request $request, $user_id)
    {
        $request->validate([
            'coin' => 'required|string',
            'amount' => 'required|numeric',
            'usd_amount' => 'nullable|numeric',
            'action' => 'required|string',
            'trading_mode' => 'nullable|string',
        ]);

        $mode = $request->trading_mode ?? 'crypto';

        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user_id, 'symbol' => strtoupper($request->coin), 'trading_mode' => $mode],
            ['coin' => strtoupper($request->coin), 'address' => 'admin-credited', 'balance' => 0, 'usd_value' => 0]
        );

        $usdCredited = $request->usd_amount ?? ($request->amount * $this->liveCoinPrice($request->coin));

        $wallet->balance += $request->amount;
        $wallet->usd_value += $usdCredited;
        $wallet->save();

        WalletLog::create([
            'user_id' => $user_id,
            'coin' => $wallet->symbol,
            'amount' => $request->amount,
            'usd_value' => $usdCredited,
            'action' => $request->action,
        ]);

        return response()->json($wallet);
    }

    // =========================
    // ACTIVE USERS
    // =========================
    public function listActiveUsers()
    {
        return response()->json(
            User::where('status', 'active')->with('wallets')->get()
        );
    }

    // =========================
    // WALLET LIST
    // =========================
    public function listWallets()
    {
        return response()->json(Wallet::with('user')->get());
    }

    // =========================
    // ANALYTICS
    // =========================
    public function listAnalytics()
    {
        $top = User::with(['aiInvestments', 'wallets'])
            ->get()
            ->map(function ($u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'total_investment' =>
                        $u->aiInvestments->sum('amount') +
                        $u->wallets->sum('usd_value'),
                ];
            })
            ->sortByDesc('total_investment')
            ->take(10)
            ->values();

        return response()->json(['top_users' => $top]);
    }

    // =========================
    // USER KYC
    // =========================
    public function listUsersKYC(Request $request)
    {
        $query = UserKyc::with('user');

        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }

        return response()->json($query->get());
    }

    public function approveKYC($user_id)
    {
        $kyc = UserKyc::where('user_id', $user_id)->firstOrFail();

        $kyc->update([
            'status' => 'approved',
            'verified_at' => now(),
            'rejection_reason' => null,
        ]);

        return response()->json([
            'message' => 'KYC approved',
            'kyc' => $kyc,
        ]);
    }

    public function rejectKYC(Request $request, $user_id)
    {
        $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $kyc = UserKyc::where('user_id', $user_id)->firstOrFail();

        $kyc->update([
            'status' => 'rejected',
            'verified_at' => null,
            'rejection_reason' => $request->reason,
        ]);

        return response()->json([
            'message' => 'KYC rejected',
            'kyc' => $kyc,
        ]);
    }

    // =========================
    // USER BALANCE PANEL UPDATE
    // =========================
    public function updateUserBalances(Request $request, $id)
    {
        $request->validate([
            'total_usdt' => 'nullable|numeric',
            'conversion_ngn' => 'nullable|numeric',
            'asset_balance' => 'nullable|numeric',
            'investment_balance' => 'nullable|numeric',
            'ai_investment_balance' => 'nullable|numeric',
        ]);

        $user = User::findOrFail($id);

        $user->update($request->only([
            'total_usdt',
            'conversion_ngn',
            'asset_balance',
            'investment_balance',
            'ai_investment_balance',
        ]));

        return response()->json([
            'message' => 'User balances updated successfully',
            'data' => $user,
        ]);
    }

    // =================================================================
    // VERIFICATION CODE SYSTEM (MAX 4 TYPES)
    // =================================================================
    public function listVerificationCodes()
    {
        return response()->json(VerificationCode::all());
    }

    public function createVerificationCode(Request $request)
    {
        $request->validate([
            'name' => 'required|string|unique:verification_codes,name',
            'header' => 'required|string|max:100',
            'description' => 'required|string|max:500',
            'code' => 'required|digits:6|unique:verification_codes,code',
        ]);

        if (VerificationCode::count() >= 4) {
            return response()->json([
                'error' => 'Maximum of 4 verification types allowed',
            ], 400);
        }

        $verification = VerificationCode::create([
            'name' => $request->name,
            'header' => $request->header,
            'description' => $request->description,
            'code' => $request->code,
            'active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Verification code created successfully',
            'data' => $verification,
        ]);
    }

    public function toggleVerificationCode($id)
    {
        $code = VerificationCode::findOrFail($id);
        $code->active = !$code->active;
        $code->save();

        return response()->json([
            'success' => true,
            'message' => 'Verification code status updated',
            'data' => $code,
        ]);
    }

    public function deleteVerificationCode($id)
    {
        VerificationCode::destroy($id);

        return response()->json([
            'success' => true,
            'message' => 'Verification code deleted',
        ]);
    }

    public function listUserVerificationEntries()
    {
        return response()->json(
            UserVerificationEntry::with(['user', 'verificationCode'])->get()
        );
    }
}