<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use Illuminate\Http\Request;

class LedgerController extends Controller
{
    /**
     * POST /api/deposit
     *
     * This was an empty class before — the route existed but calling it
     * threw a BadMethodCallException. Creates a pending deposit claim
     * (e.g. "I sent 0.01 BTC to my deposit address") for an admin to
     * verify and approve, which is what actually credits the wallet —
     * see DepositController::approve().
     */
    public function deposit(Request $request)
    {
        $method = $request->input('method', 'crypto');

        if ($method === 'bank') {
            $data = $request->validate([
                'amount' => 'required|numeric|min:1', // USD
                'reference' => 'nullable|string',
            ]);

            $deposit = Deposit::create([
                'user_id' => $request->user()->id,
                'coin' => 'USDT',
                'amount' => $data['amount'],
                'usd_amount' => $data['amount'],
                'status' => 'pending',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Bank deposit submitted for review — your balance will update once confirmed.',
                'deposit' => $deposit,
            ]);
        }

        $data = $request->validate([
            'coin' => 'required|string|in:BTC,ETH,SOL,USDT',
            'amount' => 'required|numeric|min:0.00000001',
            'usd_amount' => 'nullable|numeric|min:0',
        ]);

        $deposit = Deposit::create([
            'user_id' => $request->user()->id,
            'coin' => $data['coin'],
            'amount' => $data['amount'],
            'usd_amount' => $data['usd_amount'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Deposit submitted for review — your balance will update once confirmed.',
            'deposit' => $deposit,
        ]);
    }
}
