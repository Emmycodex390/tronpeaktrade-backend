<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Withdrawal;
use App\Models\Transaction;
use App\Models\Order;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * GET /api/transactions
     *
     * There's no single "transactions" table — deposits, withdrawals,
     * internal transfers, and trades all live in their own tables with
     * their own shapes. This normalizes all of them into one common
     * format and sorts by date, rather than fabricating a feed.
     */
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $rows = collect();

        foreach (Deposit::where('user_id', $userId)->get() as $d) {
            $rows->push([
                'type' => 'deposit',
                'description' => "Deposit · {$d->coin}",
                'amount' => (float) $d->amount,
                'symbol' => $d->coin,
                'status' => $d->status,
                'created_at' => $d->created_at,
            ]);
        }

        foreach (Withdrawal::where('user_id', $userId)->get() as $w) {
            $rows->push([
                'type' => 'withdrawal',
                'description' => $w->type === 'crypto' ? "Withdrawal · {$w->coin}" : 'Withdrawal · Bank',
                'amount' => -1 * (float) $w->amount,
                'symbol' => $w->coin ?? 'USD',
                'status' => $w->status,
                'created_at' => $w->created_at,
            ]);
        }

        foreach (Transaction::where('sender_id', $userId)->orWhere('receiver_id', $userId)->get() as $t) {
            $isSender = $t->sender_id == $userId;
            $rows->push([
                'type' => 'transfer',
                'description' => $isSender ? 'Transfer sent' : 'Transfer received',
                'amount' => $isSender ? -1 * (float) $t->amount : (float) $t->amount,
                'symbol' => 'USDT',
                'status' => $t->status,
                'created_at' => $t->created_at,
            ]);
        }

        foreach (Order::where('user_id', $userId)->where('status', 'filled')->get() as $o) {
            $rows->push([
                'type' => 'trade',
                'description' => "{$o->side} {$o->pair}",
                'amount' => (float) $o->amount,
                'symbol' => explode('/', $o->pair)[0] ?? '',
                'status' => 'filled',
                'created_at' => $o->created_at,
            ]);
        }

        $sorted = $rows->sortByDesc('created_at')->values()->take(100);

        return response()->json(['success' => true, 'data' => $sorted]);
    }
}
