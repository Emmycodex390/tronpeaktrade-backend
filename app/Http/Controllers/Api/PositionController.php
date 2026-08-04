<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Models\Wallet;
use App\Models\Ledger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PositionController extends Controller
{
    // GET /api/positions?mode=forex
    public function index(Request $request)
    {
        $user = $request->user();
        $mode = $request->query('mode');

        $query = Position::where('user_id', $user->id)->where('status', 'open');

        if ($mode) {
            $query->where('mode', $mode);
        }

        $positions = $query->get();

        return response()->json($positions);
    }  
    
    public function close(Request $request, $id)
{
    $user = $request->user();

    $position = Position::where('user_id', $user->id)
        ->where('id', $id)
        ->where('status', 'open')
        ->first();

    if (!$position) {
        return response()->json(['error' => 'Position not found or already closed'], 404);
    }

    $currentPrice = $request->input('price') ?? $position->entry_price;

    DB::beginTransaction();
    try {
        // QUOTE currency (e.g., BTC/USDT → USDT)
        [$base, $quote] = explode('/', $position->pair);

        $wallet = Wallet::firstWhere([
            ['user_id', $user->id],
            ['symbol', $quote],
            ['trading_mode', $position->mode],
        ]);

        if (!$wallet) {
            return response()->json(['error' => 'Wallet not found'], 400);
        }

        // Calculate PNL
        if ($position->side === 'buy') {
            $pnl = ($currentPrice - $position->entry_price) * $position->size * $position->leverage;
        } else {
            $pnl = ($position->entry_price - $currentPrice) * $position->size * $position->leverage;
        }

        // Restore margin
        $wallet->margin -= $position->margin_used;

        // Add margin + pnl
        $wallet->balance += ($position->margin_used + $pnl);
        $wallet->save();

        // Update position
        $position->update([
            'exit_price' => $currentPrice,
            'pnl' => $pnl,
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        // Ledger entry
        Ledger::create([
            'user_id' => $user->id,
            'type'    => 'close-position',
            'amount'  => $position->margin_used,
            'pnl'     => $pnl,
            'symbol'  => $quote,
            'mode'    => $position->mode,
            'note'    => "Closed {$position->pair} {$position->side} position",
        ]);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Position closed',
            'position' => $position,
            'wallet' => $wallet,
        ]);

    } catch (\Throwable $e) {
        DB::rollBack();
        return response()->json([
            'error' => 'Close failed',
            'message' => $e->getMessage(),
        ], 500);
    }
}
}