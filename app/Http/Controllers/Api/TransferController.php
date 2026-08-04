<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransferController extends Controller
{
    public function send(Request $request)
    {
        $data = $request->validate([
            'receiver' => 'required|string',  // username OR email
            'amount' => 'required|numeric|min:1',
            'description' => 'nullable|string',
        ]);

        $sender = $request->user();

        $receiver = User::where('username', $data['receiver'])
            ->orWhere('email', $data['receiver'])
            ->first();

        if (!$receiver) {
            return response()->json(['error' => 'Receiver not found'], 404);
        }

        if ($receiver->id == $sender->id) {
            return response()->json(['error' => 'You cannot send money to yourself'], 400);
        }

        $amount = $data['amount'];

        // Uses the USDT wallet — the same one deposits, withdrawals, and
        // orders all use as the "cash" asset. Previously this moved money
        // through a separate generic 'USD' symbol wallet that nothing
        // else in the app ever touched, so a real user's USDT balance
        // from trading would never show up here, and transfers sent to
        // them would land somewhere they'd never see on their dashboard.
        $senderWallet = Wallet::firstOrCreate(
            ['user_id' => $sender->id, 'symbol' => 'USDT', 'trading_mode' => 'crypto'],
            ['coin' => 'USDT', 'address' => 'internal-transfer', 'balance' => 0]
        );

        $receiverWallet = Wallet::firstOrCreate(
            ['user_id' => $receiver->id, 'symbol' => 'USDT', 'trading_mode' => 'crypto'],
            ['coin' => 'USDT', 'address' => 'internal-transfer', 'balance' => 0]
        );

        if ($senderWallet->balance < $amount) {
            return response()->json(['error' => 'Insufficient balance'], 400);
        }

        DB::beginTransaction();
        try {
            $senderWallet->balance -= $amount;
            $senderWallet->save();

            $receiverWallet->balance += $amount;
            $receiverWallet->save();

            $transaction = Transaction::create([
                'sender_id' => $sender->id,
                'receiver_id' => $receiver->id,
                'amount' => $amount,
                'description' => $data['description'] ?? null,
                'type' => 'transfer',
                'status' => 'completed',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transfer successful',
                'transaction' => $transaction,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Transfer failed', 'message' => $e->getMessage()], 500);
        }
    }
}