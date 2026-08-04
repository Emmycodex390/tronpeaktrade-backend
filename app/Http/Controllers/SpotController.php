<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Wallet;
use Illuminate\Http\Request;

class SpotController extends Controller
{
    /**
     * GET /api/user/wallet/spot
     *
     * This class was completely empty before — the route existed but
     * calling it threw a method-not-found error. Returns the user's
     * crypto wallets with a total, mirroring the same shape the
     * dashboard already uses from WalletController.
     */
    public function balance(Request $request)
    {
        $wallets = Wallet::where('user_id', $request->user()->id)
            ->where('trading_mode', 'crypto')
            ->get();

        return response()->json([
            'success' => true,
            'total_usd' => $wallets->sum('usd_value'),
            'wallets' => $wallets,
        ]);
    }

    /**
     * GET /api/user/spot-orders/open
     */
    public function openOrders(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => Order::where('user_id', $request->user()->id)
                ->where('status', 'pending')
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    /**
     * GET /api/user/spot-trades/recent
     */
    public function recentTrades(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => Order::where('user_id', $request->user()->id)
                ->where('status', 'filled')
                ->orderByDesc('created_at')
                ->take(30)
                ->get(),
        ]);
    }
}
