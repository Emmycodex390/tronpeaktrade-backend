<?php

namespace App\Http\Controllers;

use App\Models\AiInvestment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AiInvestmentController extends Controller
{
    // ✅ Create new investment
    public function store(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:10',
            'daily_return' => 'required|numeric|min:0.1',
            'duration_days' => 'required|integer|min:1',
        ]);

        $expected_profit = ($validated['amount'] * ($validated['daily_return'] / 100)) * $validated['duration_days'];

        $investment = AiInvestment::create([
            'user_id' => Auth::id() ?? 1,
            'plan_name' => 'AI Smart Plan',
            'amount' => $validated['amount'],
            'daily_return' => $validated['daily_return'],
            'duration_days' => $validated['duration_days'],
            'expected_profit' => $expected_profit,
            'status' => 'running',
            'transaction_id' => 'AIINV-' . strtoupper(Str::random(8)),
            'start_date' => Carbon::now(),
            'end_date' => Carbon::now()->addDays($validated['duration_days']),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'AI Investment started successfully',
            'data' => $investment
        ]);
    }

    // ✅ Get user investments
    public function index()
    {
        $investments = AiInvestment::where('user_id', Auth::id() ?? 1)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($investments);
    }

    // ✅ Complete investment manually
    public function complete($id)
    {
        $investment = AiInvestment::where('id', $id)->where('user_id', Auth::id() ?? 1)->firstOrFail();
        $investment->status = 'completed';
        $investment->earned_profit = $investment->expected_profit;
        $investment->save();

        return response()->json([
            'success' => true,
            'message' => 'Investment completed successfully',
            'data' => $investment
        ]);
    }
    // ✅ This runs daily profit updates when admin clicks the button
    public function runDailyProfitUpdate()
    {
        $today = Carbon::now();

        // Get all running investments
        $investments = AiInvestment::where('status', 'running')->get();

        if ($investments->isEmpty()) {
            return response()->json(['message' => 'No active investments found.'], 200);
        }

        foreach ($investments as $inv) {
            // Calculate today's profit
            $dailyProfit = ($inv->amount * $inv->daily_return) / 100;

            // Add to earned profit
            $inv->earned_profit += $dailyProfit;

            // Check if plan completed
            if ($inv->end_date && Carbon::parse($inv->end_date)->lessThanOrEqualTo($today)) {
                $inv->status = 'completed';
            }

            $inv->save();
        }

        return response()->json([
            'message' => '✅ Daily profit successfully updated for all running investments.',
            'count' => $investments->count()
        ], 200);
    }
}