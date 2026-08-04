<?php  

use Illuminate\Http\Request;  
use Illuminate\Support\Facades\Route;  
use Illuminate\Support\Facades\Http;  
use App\Http\Controllers\Api\WalletController;  
use App\Http\Controllers\Api\WithdrawalController;  
use App\Http\Controllers\Api\NowNodesWebhookController;  
use App\Http\Controllers\Api\TradeController;  
use App\Http\Controllers\Api\LedgerController;  
use App\Http\Controllers\Api\UserStakeController;  
use App\Http\Controllers\Api\StakingPlanController;  
use App\Http\Controllers\Api\UserController;  
use App\Http\Controllers\Api\UserInteractionController;  
use App\Http\Controllers\Api\FuturesController;  
use App\Http\Controllers\SpotController;  
use App\Models\PushSubscription;  
use App\Http\Controllers\ChatController;  
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\InvestmentPaymentController;

/*  
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

Route::post('/nowpayments/create-invoice', function (Request $request) {
    $response = Http::withHeaders([
        'x-api-key' => env('NOWPAYMENTS_API_KEY'),
    ])->post('https://api.nowpayments.io/v1/invoice', [
        'price_amount' => $request->price_amount,
        'price_currency' => 'usd',
        'order_description' => $request->order_description,
        'success_url' => 'https://yourfrontend.com/payment-success',
        'cancel_url' => 'https://yourfrontend.com/payment-cancel',
    ]);

    return $response->json();
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/investment-payments', [InvestmentPaymentController::class, 'index']);
    Route::post('/investment-payments', [InvestmentPaymentController::class, 'store']);
});

// Public callback from NOWPayments webhook
Route::post('/investment-payments/update', [InvestmentPaymentController::class, 'updateStatus']);

// ✅ Chat routes
Route::middleware('auth:sanctum')->prefix('chat')->group(function () {
  
    Route::get('/{chatId}/messages', [ChatController::class, 'fetchMessages']);
    Route::post('/send', [ChatController::class, 'sendMessage']);
    Route::post('/chat/{chatId}/read', [ChatController::class, 'markAsRead']);
});

// 🔹 Get crypto ticker
Route::get('/ticker/{symbol}', function ($symbol) {  
    $symbol = strtoupper($symbol);  
    $response = Http::get("https://data-api.binance.vision/api/v3/ticker/24hr", ['symbol' => $symbol]);  
    if ($response->failed()) {  
        $fallback = Http::get("https://api.binance.com/api/v3/ticker/24hr", ['symbol' => $symbol]);  
        return $fallback->json();  
    }  
    return $response->json();  
});

// 🔹 Get Binance exchange info
Route::get('/binance/exchange-info', function () {  
    try {  
        $spot = Http::timeout(60)->get('https://testnet.binance.vision/api/v3/exchangeInfo')->json();  
    } catch (\Exception $e) {  
        return response()->json(['error' => 'Spot failed: ' . $e->getMessage()], 500);  
    }  
    try {  
        $futures = Http::timeout(60)->get('https://testnet.binance.vision/fapi/v1/exchangeInfo')->json();  
    } catch (\Exception $e) {  
        return response()->json(['error' => 'Futures failed: ' . $e->getMessage()], 500);  
    }  
    return response()->json([  
        'spot' => $spot,  
        'futures' => $futures,  
    ]);  
});

// 🔔 Webhook from NowNodes
Route::post('/webhooks/nownodes', [NowNodesWebhookController::class, 'handle']);

// Staking plans (public)
Route::get('/staking-plans', [StakingPlanController::class, 'index']);  
Route::get('/staking-plans/sync', [StakingPlanController::class, 'sync']);  

// Stakes
Route::get('/stakes', [UserStakeController::class, 'index']);  
Route::post('/stakes', [UserStakeController::class, 'store']);  
Route::post('/stakes/subscribe', [UserStakeController::class, 'subscribeUserStake']);  
Route::post('/stakes/claim', [UserStakeController::class, 'claim']);  

/*
|--------------------------------------------------------------------------
| Authenticated Routes (Requires Sanctum)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {  

    // MT5 Trading
    Route::prefix('mt5')->group(function () {  
        Route::post('/trade', [TradeController::class, 'trade']);    
        Route::get('/positions', [TradeController::class, 'positions'])->name('mt5.positions');  
    });

    // Push subscription
    Route::post('/push/subscribe', function (Request $request) {  
        $request->validate([  
            'endpoint' => 'required',  
            'keys.p256dh' => 'required',  
            'keys.auth' => 'required',  
        ]);  
        PushSubscription::updateOrCreate(  
            ['user_id' => auth()->id(), 'endpoint' => $request->endpoint],  
            [  
                'public_key' => $request->keys['p256dh'],  
                'auth_token' => $request->keys['auth'],  
            ]  
        );  
        return response()->json(['success' => true]);  
    });

    // Ledger
    Route::prefix('ledger')->group(function () {  
        Route::get('/', [LedgerController::class, 'index']);  
        Route::post('/deposit', [LedgerController::class, 'deposit']);  
        Route::post('/withdraw', [LedgerController::class, 'withdraw']);  
    });

    // Wallet
    Route::prefix('wallet')->group(function () {  
        Route::post('/create', [WalletController::class, 'createAddress']);  
        Route::get('/', [WalletController::class, 'myWallets']);  
    });

    // Notifications
    Route::get('/notifications', [UserInteractionController::class, 'getNotifications']);  
    Route::post('/notifications/{id}/read', [UserInteractionController::class, 'markNotificationRead']);  

    // Push controller
    Route::post('/push/subscribe', [PushController::class, 'subscribe']);  

    // Withdrawals
    Route::post('/withdraw', [WithdrawalController::class, 'withdraw']);  

    // Futures
    Route::get('/futures/positions', [FuturesController::class, 'positions']);  

    // Staking plans (auth)
    Route::get('/staking-plans', [StakingPlanController::class, 'index']);  
    Route::post('/staking-plans/sync', [StakingPlanController::class, 'sync']);  

    // Spot
    Route::get('/user/wallet/spot', [SpotController::class, 'balance']);  
    Route::get('/user/spot-orders/open', [SpotController::class, 'openOrders']);  
    Route::get('/user/spot-trades/recent', [SpotController::class, 'recentTrades']);  

    // Wallets
    Route::get('/wallets', [WalletController::class, 'myWallets']);  
    Route::post('/wallets/address', [WalletController::class, 'createAddress']);  
    Route::post('/wallets/send', [WalletController::class, 'send']);  

    // User info
    Route::get('/user', [UserController::class, 'me']);  
});