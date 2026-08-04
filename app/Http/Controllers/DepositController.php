<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Deposit;
use App\Models\Withdrawal;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class DepositController extends Controller
{
    public function list()
    {
        return response()->json(Deposit::with('user')->get());
    }

    public function approve($id)
    {
        $deposit = Deposit::findOrFail($id);

        if ($deposit->status === 'approved') {
            return response()->json($deposit); // already credited, don't double-credit
        }

        DB::beginTransaction();
        try {
            $wallet = Wallet::firstOrCreate(
                ['user_id' => $deposit->user_id, 'symbol' => $deposit->coin, 'trading_mode' => 'crypto'],
                ['coin' => $deposit->coin, 'address' => 'deposit-credited', 'balance' => 0]
            );

            $wallet->balance += $deposit->amount;
            $wallet->save();

            $deposit->status = 'approved';
            $deposit->save();

            DB::commit();
            return response()->json($deposit);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to approve deposit', 'message' => $e->getMessage()], 500);
        }
    }

    public function reject($id)
    {
        $deposit = Deposit::findOrFail($id);
        $deposit->status = 'rejected';
        $deposit->save();
        return response()->json($deposit);
    }
}

class WithdrawalController extends Controller
{
    public function list()
    {
        return response()->json(Withdrawal::with('user')->get());
    }

    public function approve($id)
    {
        $withdrawal = Withdrawal::findOrFail($id);
        $withdrawal->status = 'approved';
        $withdrawal->save();
        return response()->json($withdrawal);
    }

    public function reject($id)
    {
        // Withdrawal was already deducted from the wallet at request time
        // (see Api\WithdrawalController), so rejecting it should refund
        // the user rather than just changing a label with their money
        // stuck in limbo.
        $withdrawal = Withdrawal::findOrFail($id);

        if ($withdrawal->status !== 'rejected') {
            DB::beginTransaction();
            try {
                if ($withdrawal->type === 'crypto' && $withdrawal->coin) {
                    $wallet = Wallet::firstWhere([
                        ['user_id', $withdrawal->user_id],
                        ['symbol', $withdrawal->coin],
                        ['trading_mode', 'crypto'],
                    ]);

                    // The withdrawal's `amount` is stored in USD terms
                    // (the table only has 2 decimal places — not enough
                    // precision for most coins), so refunding it requires
                    // converting back through a live price rather than
                    // adding the raw number into a coin-denominated
                    // wallet, which would wildly overcredit the account.
                    if ($wallet) {
                        $ids = ['BTC' => 'bitcoin', 'ETH' => 'ethereum', 'SOL' => 'solana'];
                        $geckoId = $ids[$withdrawal->coin] ?? null;
                        $price = null;

                        if ($geckoId) {
                            try {
                                $resp = Http::timeout(6)->get('https://api.coingecko.com/api/v3/simple/price', [
                                    'ids' => $geckoId,
                                    'vs_currencies' => 'usd',
                                ]);
                                if ($resp->successful()) {
                                    $price = $resp->json("{$geckoId}.usd");
                                }
                            } catch (\Exception $e) {}
                        }

                        if (!$price) {
                            DB::rollBack();
                            return response()->json([
                                'error' => 'Unable to fetch a live price to process this refund — try again shortly.',
                            ], 503);
                        }

                        $wallet->balance += $withdrawal->amount / $price;
                        $wallet->save();
                    }
                } else {
                    $wallet = Wallet::firstWhere([
                        ['user_id', $withdrawal->user_id],
                        ['symbol', 'USDT'],
                        ['trading_mode', 'crypto'],
                    ]);

                    if ($wallet) {
                        $wallet->balance += $withdrawal->amount;
                        $wallet->save();
                    }
                }

                $withdrawal->status = 'rejected';
                $withdrawal->save();

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                return response()->json(['error' => 'Failed to reject withdrawal', 'message' => $e->getMessage()], 500);
            }
        }

        return response()->json($withdrawal);
    }
}