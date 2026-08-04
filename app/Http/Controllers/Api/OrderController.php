<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Wallet;
use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Canonical OrderController.
 *
 * There used to be a second, thinner OrderController at
 * App\Http\Controllers\OrderController that routes/api.php was actually
 * wired to. It never touched wallet balances or created positions (so
 * "buy" orders succeeded without ever debiting anything), and several
 * routes (myTrades, update, delete) pointed at methods that didn't exist
 * on it at all. This class is now the single source of truth and
 * routes/api.php has been repointed here.
 */
class OrderController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'pair'          => 'required|string',     // Example: BTC/USDT
            'side'          => 'required|in:buy,sell',
            'type'          => 'required|in:market,limit,stop',
            'price'         => 'nullable|numeric',
            'trigger_price' => 'nullable|numeric',
            'amount'        => 'required|numeric|min:0.00001',
            'trading_mode'  => 'nullable|string', // crypto|forex|futures
            'leverage'      => 'nullable|integer|min:1|max:100',
        ]);

        $user = $request->user();
        $mode = $data['trading_mode'] ?? 'crypto';

        [$base, $quote] = array_map('trim', explode('/', strtoupper($data['pair'])));
        $price = $data['price'] ?? null;

        if ($data['side'] === 'buy' && $data['type'] !== 'market' && !$price) {
            return response()->json(['error' => 'Price required for buy limit/stop orders'], 422);
        }

        DB::beginTransaction();
        try {
            // For spot/crypto: reserve funds immediately on order placement.
            if ($mode === 'crypto') {
                if ($data['side'] === 'buy') {
                    if (!$price && $data['type'] !== 'market') {
                        return response()->json(['error' => 'Price required'], 422);
                    }
                    $cost = $data['amount'] * ($price ?? 0);
                    $quoteWallet = Wallet::firstWhere([
                        ['user_id', $user->id],
                        ['symbol', $quote],
                        ['trading_mode', 'crypto'],
                    ]);

                    if (!$quoteWallet || $quoteWallet->balance < $cost) {
                        return response()->json(['error' => "Insufficient $quote balance"], 400);
                    }
                    $quoteWallet->balance -= $cost;
                    $quoteWallet->save();
                } else { // sell
                    $baseWallet = Wallet::firstWhere([
                        ['user_id', $user->id],
                        ['symbol', $base],
                        ['trading_mode', 'crypto'],
                    ]);
                    if (!$baseWallet || $baseWallet->balance < $data['amount']) {
                        return response()->json(['error' => "Insufficient $base balance"], 400);
                    }
                    $baseWallet->balance -= $data['amount'];
                    $baseWallet->save();
                }
            }

            // For leveraged modes (forex/futures) we calculate margin and,
            // for market orders, open a position immediately.
            if (in_array($mode, ['forex', 'futures'])) {
                $entryPrice = $price ?? 0;
                $notional = $entryPrice * $data['amount'];

                $quoteWallet = Wallet::firstWhere([
                    ['user_id', $user->id],
                    ['symbol', $quote],
                    ['trading_mode', $mode],
                ]);

                $userLeverage = $data['leverage']
                    ?? (($quoteWallet && $quoteWallet->leverage) ? $quoteWallet->leverage : 1);
                $marginRequired = $userLeverage > 0 ? ($notional / $userLeverage) : $notional;

                if (!$quoteWallet || $quoteWallet->balance < $marginRequired) {
                    return response()->json(['error' => "Insufficient {$quote} balance for margin"], 400);
                }

                $quoteWallet->balance -= $marginRequired;
                $quoteWallet->margin += $marginRequired;
                $quoteWallet->save();

                if ($data['type'] === 'market') {
                    Position::create([
                        'user_id' => $user->id,
                        'pair' => strtoupper($data['pair']),
                        'side' => $data['side'],
                        'entry_price' => $entryPrice,
                        'size' => $data['amount'],
                        'margin_used' => $marginRequired,
                        'leverage' => $userLeverage,
                        'mode' => $mode,
                        'status' => 'open',
                    ]);
                }
            }

            $order = Order::create([
                'user_id'       => $user->id,
                'pair'          => strtoupper($data['pair']),
                'side'          => $data['side'],
                'type'          => $data['type'],
                'price'         => $price,
                'amount'        => $data['amount'],
                'trigger_price' => $data['trigger_price'] ?? null,
                'order_id'      => 'ORD-' . strtoupper(Str::random(10)),
                'status'        => $data['type'] === 'market' ? 'filled' : 'pending',
            ]);

            DB::commit();

            return response()->json(['success' => true, 'order' => $order]);
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('Order create failed: '.$e->getMessage());
            return response()->json(['error' => 'Order failed', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/orders — full order history for the authenticated user.
     */
    public function index(Request $request)
    {
        $orders = Order::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $orders]);
    }

    /**
     * GET /api/trades — kept as a separate endpoint name for the frontend,
     * currently identical to index().
     */
    public function myTrades(Request $request)
    {
        return $this->index($request);
    }

    /**
     * PUT /api/orders/{id} — adjust a still-pending limit/stop order.
     * Only price and trigger_price can change; changing amount would
     * require re-reconciling reserved funds, so it's intentionally left
     * out until a real matching/reservation engine exists.
     */
    public function update(Request $request, $id)
    {
        $order = Order::where('id', $id)->where('user_id', $request->user()->id)->firstOrFail();

        if ($order->status !== 'pending') {
            return response()->json(['error' => 'Only pending orders can be edited'], 422);
        }

        $data = $request->validate([
            'price'         => 'nullable|numeric',
            'trigger_price' => 'nullable|numeric',
        ]);

        $order->fill($data);
        $order->save();

        return response()->json(['success' => true, 'order' => $order]);
    }

    /**
     * POST /api/orders/{id}/cancel — cancel a pending order and refund
     * whatever was reserved for it at creation time.
     */
    public function cancel(Request $request, $id)
    {
        $order = Order::where('id', $id)->where('user_id', $request->user()->id)->firstOrFail();

        if ($order->status !== 'pending') {
            return response()->json(['error' => 'Only pending orders can be cancelled'], 422);
        }

        [$base, $quote] = array_map('trim', explode('/', strtoupper($order->pair)));

        DB::beginTransaction();
        try {
            // Refund crypto spot reservations.
            if ($order->side === 'buy' && $order->price) {
                $quoteWallet = Wallet::firstWhere([
                    ['user_id', $order->user_id],
                    ['symbol', $quote],
                    ['trading_mode', 'crypto'],
                ]);
                if ($quoteWallet) {
                    $quoteWallet->balance += $order->amount * $order->price;
                    $quoteWallet->save();
                }
            } elseif ($order->side === 'sell') {
                $baseWallet = Wallet::firstWhere([
                    ['user_id', $order->user_id],
                    ['symbol', $base],
                    ['trading_mode', 'crypto'],
                ]);
                if ($baseWallet) {
                    $baseWallet->balance += $order->amount;
                    $baseWallet->save();
                }
            }

            $order->status = 'cancelled';
            $order->save();

            DB::commit();
            return response()->json(['success' => true, 'order' => $order]);
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('Order cancel failed: '.$e->getMessage());
            return response()->json(['error' => 'Cancel failed', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/orders/{id} — remove a pending or already-cancelled
     * order from history. Filled orders are kept for the trade record.
     */
    public function delete(Request $request, $id)
    {
        $order = Order::where('id', $id)->where('user_id', $request->user()->id)->firstOrFail();

        if (!in_array($order->status, ['pending', 'cancelled'])) {
            return response()->json(['error' => 'Only pending or cancelled orders can be deleted'], 422);
        }

        $order->delete();

        return response()->json(['success' => true]);
    }
}
