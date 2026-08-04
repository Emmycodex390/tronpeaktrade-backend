<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    /**
     * -----------------------------------------------------
     * USER WALLET LIST (General – your original endpoint)
     * GET /api/wallets
     * -----------------------------------------------------
     */
    public function index()
    {
        $wallets = auth()->user()->wallets()->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'wallets' => $wallets->map(function ($wallet) {
                    return [
                        'id'        => $wallet->id,
                        'name'      => $wallet->name ?? $wallet->symbol,
                        'symbol'    => strtoupper($wallet->symbol),
                        'network'   => $wallet->network,
                        'address'   => $wallet->address,
                        'balance'   => (float) $wallet->balance,
                        'usd_value' => (float) ($wallet->usd_value ?? 0),
                        'trading_mode' => $wallet->trading_mode,
                        'margin'    => (float) $wallet->margin,
                        'leverage'  => (int) ($wallet->leverage ?? 1),
                    ];
                }),
            ],
        ]);
    }

    /**
     * -----------------------------------------------------
     * SINGLE WALLET INFO
     * GET /api/wallets/{wallet}
     * -----------------------------------------------------
     */
    public function show(Wallet $wallet)
    {
        $this->authorize('view', $wallet);

        return response()->json([
            'status' => 'success',
            'wallet' => $wallet,
        ]);
    }

    /**
     * -----------------------------------------------------
     * TRADING MODE WALLETS
     * GET /api/wallets/{mode}
     * -----------------------------------------------------
     * Returns ALL wallets under one mode:
     * crypto | forex | futures
     * -----------------------------------------------------
     */
    public function byMode($mode, Request $request)
    {
        $user = $request->user();

        $wallets = Wallet::where('user_id', $user->id)
            ->where('trading_mode', $mode)
            ->get();

        return response()->json($wallets);
    }

    /**
     * -----------------------------------------------------
     * PAIR-SPECIFIC WALLETS FOR TRADING
     * GET /api/wallets/{mode}/{pair}
     * -----------------------------------------------------
     * Example:
     * /api/wallets/crypto/BTC/USDT
     * /api/wallets/forex/EUR/USD
     * -----------------------------------------------------
     */
    public function byModeAndPair($mode, $pair, Request $request)
{
    $user = $request->user();

    // Support both BTC-USDT and BTC/USDT
    if (str_contains($pair, '-')) {
        $pair = str_replace('-', '/', $pair);
    }

    [$base, $quote] = explode('/', strtoupper($pair));

    $wallets = Wallet::where('user_id', $user->id)
        ->where('trading_mode', $mode)
        ->whereIn('symbol', [$base, $quote])
        ->get();

    return response()->json($wallets);
  }
}