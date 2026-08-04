<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class FuturesController extends Controller
{
    // Base-symbol -> CoinGecko id, for pricing open futures positions live.
    private const COINGECKO_IDS = [
        'BTC' => 'bitcoin', 'ETH' => 'ethereum', 'BNB' => 'binancecoin',
        'SOL' => 'solana', 'XRP' => 'ripple', 'ADA' => 'cardano',
        'DOGE' => 'dogecoin',
    ];

    /**
     * GET /api/futures/positions
     *
     * This previously fabricated a fake $1000 wallet balance and seeded
     * two entirely made-up positions (BTCUSDT, ETHUSDT with invented
     * entry prices and PnL) for any user with none — a genuine
     * fake-data bug, not a placeholder anyone asked for. Rewritten to
     * use the real Wallet and Position records that OrderController
     * actually creates when a futures market order is placed, with
     * unrealized PnL computed from a live price lookup rather than
     * invented numbers.
     */
    public function positions(Request $request)
    {
        $user = $request->user();

        $walletBalance = Wallet::where('user_id', $user->id)
            ->where('trading_mode', 'futures')
            ->sum('balance');

        $marginBalance = Wallet::where('user_id', $user->id)
            ->where('trading_mode', 'futures')
            ->sum('margin');

        $positions = Position::where('user_id', $user->id)
            ->where('mode', 'futures')
            ->where('status', 'open')
            ->get();

        $unrealizedTotal = 0;

        if ($positions->isNotEmpty()) {
            $baseSymbols = $positions->map(fn ($p) => explode('/', $p->pair)[0])->unique();
            $ids = $baseSymbols->map(fn ($s) => self::COINGECKO_IDS[$s] ?? null)->filter()->implode(',');

            $prices = [];
            if ($ids) {
                try {
                    $resp = Http::timeout(6)->get('https://api.coingecko.com/api/v3/simple/price', [
                        'ids' => $ids,
                        'vs_currencies' => 'usd',
                    ]);
                    if ($resp->successful()) {
                        foreach ($resp->json() as $id => $val) {
                            $symbol = array_search($id, self::COINGECKO_IDS);
                            if ($symbol) {
                                $prices[$symbol] = $val['usd'] ?? null;
                            }
                        }
                    }
                } catch (\Exception $e) {}
            }

            $positions = $positions->map(function ($p) use ($prices, &$unrealizedTotal) {
                $base = explode('/', $p->pair)[0];
                $markPrice = $prices[$base] ?? $p->entry_price;

                $pnl = $p->side === 'buy'
                    ? ($markPrice - $p->entry_price) * $p->size * $p->leverage
                    : ($p->entry_price - $markPrice) * $p->size * $p->leverage;

                $unrealizedTotal += $pnl;

                $p->mark_price = $markPrice;
                $p->unrealized_pnl = $pnl;
                return $p;
            });
        }

        return response()->json([
            'status' => 'success',
            'margin_balance' => $marginBalance,
            'wallet_balance' => $walletBalance,
            'unrealized_pnl' => $unrealizedTotal,
            'positions' => $positions,
        ]);
    }
}
