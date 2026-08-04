<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PortfolioSnapshot;
use App\Models\Wallet;
use Illuminate\Http\Request;

class PortfolioController extends Controller
{
    /**
     * GET /api/portfolio/history
     *
     * Computes the user's real current portfolio value from their
     * wallets, records a snapshot (throttled to at most one every 5
     * minutes so the table doesn't fill with a row per page load), and
     * returns whatever real history exists so far. Early on this will be
     * sparse — one or two points — and the frontend should render that
     * honestly rather than inventing a smooth line. History genuinely
     * builds up the more the user visits.
     */
    public function history(Request $request)
    {
        $user = $request->user();

        $total = Wallet::where('user_id', $user->id)->sum('usd_value');

        $lastSnapshot = PortfolioSnapshot::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->first();

        if (!$lastSnapshot || $lastSnapshot->created_at->diffInMinutes(now()) >= 5) {
            PortfolioSnapshot::create([
                'user_id' => $user->id,
                'total_usd' => $total,
                'created_at' => now(),
            ]);
        }

        $snapshots = PortfolioSnapshot::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDays(7))
            ->orderBy('created_at')
            ->get(['total_usd', 'created_at']);

        $first = $snapshots->first();
        $changeUsd = null;
        $changePercent = null;

        if ($first && $first->total_usd > 0) {
            $changeUsd = $total - $first->total_usd;
            $changePercent = ($changeUsd / $first->total_usd) * 100;
        }

        return response()->json([
            'total' => (float) $total,
            'change_usd' => $changeUsd,
            'change_percent' => $changePercent,
            'points' => $snapshots->map(fn ($s) => [
                'time' => $s->created_at->toIso8601String(),
                'value' => (float) $s->total_usd,
            ]),
        ]);
    }
}
