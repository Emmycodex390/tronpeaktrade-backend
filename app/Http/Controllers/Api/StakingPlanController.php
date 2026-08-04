<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StakingPlan;

class StakingPlanController extends Controller
{
    /**
     * GET /api/staking-plans
     *
     * This file was completely empty before (0 bytes) — the route
     * existed but calling it threw a class-not-found error before
     * Laravel could even dispatch to it.
     */
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => StakingPlan::where('active', true)
                ->orderBy('duration_days')
                ->get(),
        ]);
    }

    /**
     * GET /api/staking-plans/sync
     *
     * There's no external source this ever synced from, and no seeder
     * populating staking_plans either — so without this, the table
     * would just stay permanently empty and /staking-plans would always
     * return []. Ensures a sensible, honestly-labeled set of default
     * plans exists, without duplicating them on repeat calls.
     */
    public function sync()
    {
        $defaults = [
            ['name' => 'Flexible', 'coin' => 'USDT', 'apy' => 4.5, 'duration_days' => 0, 'min_amount' => 10, 'max_amount' => null, 'description' => 'Stake and unstake anytime, no lock-up period.'],
            ['name' => '30-Day Lock', 'coin' => 'USDT', 'apy' => 8.0, 'duration_days' => 30, 'min_amount' => 50, 'max_amount' => null, 'description' => 'Funds are locked for 30 days in exchange for a higher rate.'],
            ['name' => '90-Day Lock', 'coin' => 'USDT', 'apy' => 12.5, 'duration_days' => 90, 'min_amount' => 100, 'max_amount' => null, 'description' => 'Funds are locked for 90 days for the highest available rate.'],
        ];

        foreach ($defaults as $plan) {
            StakingPlan::updateOrCreate(
                ['name' => $plan['name'], 'coin' => $plan['coin']],
                array_merge($plan, ['active' => true])
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Staking plans synced.',
            'data' => StakingPlan::where('active', true)->orderBy('duration_days')->get(),
        ]);
    }
}
