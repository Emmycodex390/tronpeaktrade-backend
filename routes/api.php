<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

// Controllers
use App\Http\Controllers\Api\{
    WalletController,
    PortfolioController,
    NowNodesWebhookController,
    TradeController,
    LedgerController,
    InvestmentPlanController,
    UserStakeController,
    StakingPlanController,
    UserController,
    UserInteractionController,
    FuturesController,
    KycController,
    TickerController,
    UserVerificationController,
    OrderController,
    RewardController,
    PushController,
    TransactionController,
    SwapController
};

use App\Http\Controllers\{
    SpotController,
    ChatController,
    InvestmentPaymentController,
    SelarWebhookController,
    P2PController,
    AiInvestmentController,
    AdminController,
    DepositController,
    DepositAddressController,
    AdminAPIController,
    SecurityController,
    SettingsController
};

use App\Http\Controllers\Auth\{
    RegisteredUserController,
    AuthenticatedSessionController,
    PasswordResetLinkController,
    NewPasswordController,
    EmailVerificationNotificationController,
    VerifyEmailController
};

// ── Auth (token-based via Sanctum, not session/cookie) ──────────────
// Moved off the web.php/auth.php stack on purpose. These used to run
// under Laravel's default 'web' middleware group (session + CSRF
// cookie check), which required the browser to store a first-party-
// looking session cookie from a cross-domain API — something Chrome
// and Firefox block by default in 2026 regardless of how correct the
// CORS/cookie headers are. Token auth sidesteps that entirely: login/
// register hand back a Bearer token, the frontend stores and sends it
// on every request, no cookies involved.
Route::post('/register', [RegisteredUserController::class, 'store'])
    ->middleware('throttle:6,1');

Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('throttle:5,1');

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth:sanctum');

Route::post('/forgot-password', [PasswordResetLinkController::class, 'store']);
Route::post('/reset-password', [NewPasswordController::class, 'store']);

Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['auth:sanctum', 'signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
    ->middleware(['auth:sanctum', 'throttle:6,1']);

use App\Models\{PushSubscription, Ticker};


use App\Http\Controllers\Api\TransferController; 
use App\Http\Controllers\Api\PositionController;



// Keep-alive endpoint for external cron pings (e.g. cronjob.com). Does
// a real DB query on purpose — Laravel's own /up health check doesn't
// touch the database at all, so it keeps Render awake but does nothing
// for Supabase, which pauses free-tier projects after 7 days of no
// actual query activity (not just HTTP traffic). One ping here every
// ~10 minutes covers both: beats Render's 15-min spin-down and counts
// as real DB activity for Supabase.
Route::get('/ping', function () {
    $userCount = \Illuminate\Support\Facades\DB::table('users')->count();

    return response()->json([
        'status' => 'ok',
        'time' => now()->toIso8601String(),
        'db' => 'connected',
        'users' => $userCount,
    ]);
});

// Batch crypto ticker — fetches many coins in a single CoinGecko call
// instead of the per-symbol route being hit N times in parallel. Added
// because php artisan serve handles one request at a time; 15 parallel
// requests each doing 2 sequential external calls queued badly. Takes
// CoinGecko coin IDs directly (not ticker symbols) since that's what
// this endpoint needs and it avoids 15 separate "search" calls too —
// the frontend keeps a small symbol->id map for the curated asset list.
//
// Uses /coins/markets rather than /simple/price specifically because it
// also returns each coin's real logo image URL (CoinGecko's own asset
// CDN) alongside price/change — one request gets us both instead of
// needing a second call or guessing at CDN URLs.
Route::get('/ticker/crypto-batch', function (Request $request) {
    $ids = $request->query('ids', '');
    $ids = collect(explode(',', $ids))
        ->map(fn ($id) => trim($id))
        ->filter()
        ->sort()
        ->implode(',');

    if (!$ids) {
        return response()->json(['error' => 'No ids provided'], 422);
    }

    // Cache per unique id-set for 30s — ticker prices don't need to be
    // more real-time than that, and this is what actually fixes the
    // CoinGecko 429s: Render's free-tier IPs are shared across many
    // apps hammering CoinGecko's low, keyless rate limit, so caching
    // cuts our call volume down drastically instead of hitting
    // CoinGecko fresh on every single dashboard load/poll.
    $cacheKey = 'crypto-batch-' . md5($ids);
    $cached = Cache::get($cacheKey);
    if ($cached !== null) {
        return response()->json($cached);
    }

    try {
        $cg = Http::timeout(8)
            ->withHeaders(array_filter(['x-cg-demo-api-key' => config('services.coingecko.key')]))
            ->get('https://api.coingecko.com/api/v3/coins/markets', [
            'vs_currency' => 'usd',
            'ids' => $ids,
            'price_change_percentage' => '24h',
        ]);

        if ($cg->successful()) {
            $result = collect($cg->json())->mapWithKeys(fn ($coin) => [
                $coin['id'] => [
                    'symbol' => $coin['symbol'],
                    'name' => $coin['name'],
                    'image' => $coin['image'],
                    'usd' => $coin['current_price'],
                    'usd_24h_change' => $coin['price_change_percentage_24h'] ?? null,
                ],
            ])->toArray();

            Cache::put($cacheKey, $result, 30);
            Cache::put($cacheKey . '-stale', $result, 3600);

            return response()->json($result);
        }

        \Illuminate\Support\Facades\Log::warning('CoinGecko crypto-batch non-success', [
            'status' => $cg->status(),
            'body' => $cg->body(),
        ]);

        // Serve a stale cached value if we have one rather than a hard
        // error — better to show slightly-old prices than none at all
        // when CoinGecko is rate-limiting us.
        $stale = Cache::get($cacheKey . '-stale');
        if ($stale !== null) {
            return response()->json($stale);
        }

        return response()->json(['error' => 'Failed to fetch batch ticker data'], 500);
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('External API call failed', ['message' => $e->getMessage()]);

        $stale = Cache::get($cacheKey . '-stale');
        if ($stale !== null) {
            return response()->json($stale);
        }

        return response()->json(['error' => 'Failed to fetch batch ticker data'], 500);
    }
});

// Full coin detail — real market cap, volume, circulating supply, and
// description, used by the coin detail screen. Cached briefly since this
// payload is heavier than the batch ticker and the same coin gets viewed
// repeatedly in a session.
Route::get('/coins/{id}', function ($id) {
    $cacheKey = "coin-detail-{$id}";
    $cached = Cache::get($cacheKey);
    if ($cached !== null) {
        return response()->json($cached);
    }

    try {
        $response = Http::timeout(8)
            ->withHeaders(array_filter(['x-cg-demo-api-key' => config('services.coingecko.key')]))
            ->get("https://api.coingecko.com/api/v3/coins/{$id}", [
            'localization' => 'false',
            'tickers' => 'false',
            'market_data' => 'true',
            'community_data' => 'false',
            'developer_data' => 'false',
            'sparkline' => 'false',
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $result = [
                'id' => $data['id'],
                'symbol' => $data['symbol'],
                'name' => $data['name'],
                'image' => $data['image']['large'] ?? null,
                'description' => $data['description']['en'] ?? null,
                'price' => $data['market_data']['current_price']['usd'] ?? null,
                'change_percent_24h' => $data['market_data']['price_change_percentage_24h'] ?? null,
                'market_cap' => $data['market_data']['market_cap']['usd'] ?? null,
                'volume_24h' => $data['market_data']['total_volume']['usd'] ?? null,
                'circulating_supply' => $data['market_data']['circulating_supply'] ?? null,
            ];

            Cache::put($cacheKey, $result, 60);
            Cache::put($cacheKey . '-stale', $result, 3600);
            return response()->json($result);
        }
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('External API call failed', ['message' => $e->getMessage()]);
    }

    $stale = Cache::get($cacheKey . '-stale');
    if ($stale !== null) {
        return response()->json($stale);
    }

    return response()->json(['error' => 'Coin data unavailable'], 503);
});

// Real historical price chart for a coin — genuine data from CoinGecko,
// not a fabricated series. `days` matches CoinGecko's own parameter:
// 1, 7, 30, 365, or "max".
Route::get('/coins/{id}/chart', function (Request $request, $id) {
    $days = $request->query('days', '1');
    $cacheKey = "coin-chart-{$id}-{$days}";
    $cached = Cache::get($cacheKey);
    if ($cached !== null) {
        return response()->json($cached);
    }

    try {
        $response = Http::timeout(8)
            ->withHeaders(array_filter(['x-cg-demo-api-key' => config('services.coingecko.key')]))
            ->get("https://api.coingecko.com/api/v3/coins/{$id}/market_chart", [
            'vs_currency' => 'usd',
            'days' => $days,
        ]);

        if ($response->successful()) {
            $prices = collect($response->json('prices'))->map(fn ($p) => [
                'time' => $p[0],
                'value' => $p[1],
            ])->values();

            Cache::put($cacheKey, $prices, 60);
            Cache::put($cacheKey . '-stale', $prices, 3600);
            return response()->json($prices);
        }
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('External API call failed', ['message' => $e->getMessage()]);
    }

    $stale = Cache::get($cacheKey . '-stale');
    if ($stale !== null) {
        return response()->json($stale);
    }

    return response()->json(['error' => 'Chart data unavailable'], 503);
});

// Futures/perpetuals batch — routed through CoinGecko's /derivatives
// endpoint instead of hitting Binance/Bybit/OKX futures APIs directly.
// Binance's fapi.binance.com and Bybit's API are both blocked on some
// networks (confirmed via direct curl testing), but CoinGecko aggregates
// the same futures data through its own servers, which are reachable —
// so this never makes an outbound call to Binance/Bybit at all.
//
// Not pinned to a specific exchange label ("Binance (Futures)") — that
// field is one exchange out of hundreds in a large, reordering list, and
// it can rotate out of a given response entirely, which is what caused
// this route to return an empty [] even on a successful 200. Instead,
// for each wanted symbol, pick whichever perpetual contract has the
// highest 24h volume across all exchanges CoinGecko tracks — still real,
// live market data, just not tied to one exchange staying present.
Route::get('/ticker/futures-batch', function () {
    $wanted = ['BTCUSDT', 'ETHUSDT', 'BNBUSDT', 'SOLUSDT', 'XRPUSDT', 'DOGEUSDT'];

    // /derivatives is a large payload (every perpetual contract across
    // every exchange CoinGecko tracks) — it doesn't always finish inside
    // a short timeout on a slower connection. Caching a successful result
    // for 30s means most of the auto-refresh polls (every 20s) hit this
    // cache instead of re-downloading that whole payload every time,
    // which also cuts down how often we're exposed to a slow response at
    // all. Failures are never cached, so the next request retries fresh.
    $cached = Cache::get('futures-batch-ticker');
    if ($cached !== null) {
        return response()->json($cached);
    }

    try {
        $response = Http::timeout(15)
            ->withHeaders(array_filter(['x-cg-demo-api-key' => config('services.coingecko.key')]))
            ->get('https://api.coingecko.com/api/v3/derivatives');

        if ($response->successful()) {
            $all = collect($response->json());

            $results = collect($wanted)
                ->map(function ($symbol) use ($all) {
                    $match = $all
                        ->filter(fn ($row) =>
                            ($row['symbol'] ?? null) === $symbol
                            && ($row['contract_type'] ?? null) === 'perpetual'
                            && is_numeric($row['price'] ?? null)
                        )
                        ->sortByDesc(fn ($row) => (float) ($row['volume_24h'] ?? 0))
                        ->first();

                    if (!$match) {
                        return null;
                    }

                    return [
                        'symbol' => $symbol,
                        'price' => (float) $match['price'],
                        'percent_change_24h' => $match['price_percentage_change_24h'] ?? null,
                    ];
                })
                ->filter()
                ->values();

            Cache::put('futures-batch-ticker', $results, 30);

            return response()->json($results);
        }
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('External API call failed', ['message' => $e->getMessage()]);
    }

    return response()->json(['error' => 'Futures data unavailable'], 503);
});

Route::get('/ticker/crypto/{symbol}', function ($symbol) {
    $symbol = strtolower($symbol);

    // Remove USDT, USD, USDC, etc.
    $symbol = preg_replace('/(usdt|usd|usdc)$/i', '', $symbol);

    // Remove separators like "-" or "/"
    $symbol = explode('-', $symbol)[0];
    $symbol = explode('/', $symbol)[0];

    /**
     * =========================================================
     *   1️⃣ TRY COINGECKO AUTO SEARCH
     * =========================================================
     */
    try {
        $search = Http::timeout(6)
            ->withHeaders(array_filter(['x-cg-demo-api-key' => config('services.coingecko.key')]))
            ->get("https://api.coingecko.com/api/v3/search", [
            "query" => $symbol
        ]);

        if ($search->successful() && isset($search['coins'][0])) {
            $coinId = $search['coins'][0]['id'];

            $cg = Http::timeout(6)
                ->withHeaders(array_filter(['x-cg-demo-api-key' => config('services.coingecko.key')]))
                ->get("https://api.coingecko.com/api/v3/simple/price", [
                'ids' => $coinId,
                'vs_currencies' => 'usd',
                'include_24hr_change' => 'true'
            ]);

            if ($cg->successful() && isset($cg[$coinId])) {
                return [
                    "symbol" => strtoupper($symbol) . "/USDT",
                    "lastPrice" => $cg[$coinId]['usd'],
                    "priceChangePercent" => $cg[$coinId]['usd_24h_change'],
                    "source" => "coingecko-auto"
                ];
            }
        }
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('External API call failed', ['message' => $e->getMessage()]);
    }

    /**
     * =========================================================
     *   2️⃣ FALLBACK → BINANCE
     * =========================================================
     */
    try {
        $binSymbol = strtoupper($symbol) . "USDT";

        $bin = Http::timeout(6)->get("https://api.binance.com/api/v3/ticker/24hr", [
            "symbol" => $binSymbol
        ]);

        if ($bin->successful() && isset($bin['lastPrice'])) {
            return [
                "symbol" => strtoupper($symbol) . "/USDT",
                "lastPrice" => (float) $bin['lastPrice'],
                "priceChangePercent" => (float) $bin['priceChangePercent'],
                "source" => "binance"
            ];
        }
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('External API call failed', ['message' => $e->getMessage()]);
    }

    return response()->json([
        "error" => "Failed to automatically fetch ticker data for symbol: $symbol",
        "symbol" => $symbol,
    ], 500);
});


// --- Crypto ticker (Spot) — plain Binance 24hr lookup, no CoinGecko fallback.
// Moved here from inside the auth:sanctum group, where it was mistakenly
// requiring login despite being commented "PUBLIC ROUTES" — that blocked
// anyone not signed in from seeing live prices on the Markets page.
Route::get('/ticker/{symbol}', function ($symbol) {
    $symbol = strtoupper($symbol);
    try {
        $response = Http::timeout(6)->get("https://data-api.binance.vision/api/v3/ticker/24hr", ['symbol' => $symbol]);

        if ($response->failed()) {
            $response = Http::timeout(6)->get("https://api.binance.com/api/v3/ticker/24hr", ['symbol' => $symbol]);
        }

        if ($response->successful()) {
            return $response->json();
        }
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('External API call failed', ['message' => $e->getMessage()]);
    }

    // A connection-level failure (network unreachable, DNS, timeout)
    // throws instead of returning a failed Response, so it's caught
    // above rather than surfacing as an uncaught 500/HTML error page.
    return response()->json(['error' => 'Ticker unavailable', 'symbol' => $symbol], 503);
});

// --- Futures ticker (24hr) — also moved out of the auth:sanctum group for
// the same reason as /ticker/{symbol} above.
Route::get('/ticker/futures/{symbol}', function ($symbol) {
    $symbol = strtoupper($symbol);

    try {
        $response = Http::timeout(6)->get("https://fapi.binance.com/fapi/v1/ticker/24hr", ['symbol' => $symbol]);

        if ($response->ok()) {
            $data = $response->json();

            Ticker::updateOrCreate(
                ['symbol' => $symbol, 'type' => 'futures'],
                ['last_price' => $data['lastPrice'], 'price_change_percent' => $data['priceChangePercent']]
            );

            return [
                'symbol' => $symbol,
                'price' => $data['lastPrice'],
                'percent_change_24h' => $data['priceChangePercent'],
            ];
        }
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('External API call failed', ['message' => $e->getMessage()]);
    }

    return response()->json(['status' => 'error', 'message' => 'Futures asset unavailable'], 503);
});


// Binance exchange info
Route::get('/binance/exchange-info', function () {
    try {
        $spot = Http::timeout(60)->get('https://testnet.binance.vision/api/v3/exchangeInfo')->json();
        $futures = Http::timeout(60)->get('https://testnet.binance.vision/fapi/v1/exchangeInfo')->json();
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }

    return ['spot' => $spot, 'futures' => $futures];
});

// Forex rates via Frankfurter (ECB reference rates) — exchangerate.host
// now requires a paid API key for /latest (confirmed via direct curl:
// it returns a 200 with a "missing_access_key" error body, not real
// data), so this needed a second source change. Frankfurter is free,
// requires no key, and has been stable for years.
Route::get('/forex', function () {
    $fallback = response()->json([
        ['pair' => 'EUR/USD', 'price' => null, 'error' => 'API request failed'],
        ['pair' => 'GBP/USD', 'price' => null, 'error' => 'API request failed'],
        ['pair' => 'AUD/USD', 'price' => null, 'error' => 'API request failed'],
        ['pair' => 'USD/JPY', 'price' => null, 'error' => 'API request failed'],
        ['pair' => 'USD/CHF', 'price' => null, 'error' => 'API request failed'],
    ]);

    try {
        $response = Http::timeout(6)->get("https://api.frankfurter.app/latest", [
            'from' => 'USD',
        ]);
    } catch (\Exception $e) {
        return $fallback;
    }

    if ($response->failed()) {
        return $fallback;
    }

    $rates = $response->json('rates') ?? [];

    // Frankfurter with from=USD returns "1 USD = X <currency>".
    // EUR/USD, GBP/USD, AUD/USD are conventionally quoted as USD per 1
    // unit of the other currency, so those need inverting; USD/JPY and
    // USD/CHF are already in the right direction.
    $pairs = [
        'EUR/USD' => ['currency' => 'EUR', 'invert' => true],
        'GBP/USD' => ['currency' => 'GBP', 'invert' => true],
        'AUD/USD' => ['currency' => 'AUD', 'invert' => true],
        'USD/JPY' => ['currency' => 'JPY', 'invert' => false],
        'USD/CHF' => ['currency' => 'CHF', 'invert' => false],
    ];

    $results = [];
    foreach ($pairs as $label => $meta) {
        $rate = $rates[$meta['currency']] ?? null;

        if ($rate === null || $rate <= 0) {
            $results[] = ['pair' => $label, 'price' => null, 'error' => 'Rate unavailable'];
            continue;
        }

        $price = $meta['invert'] ? 1 / $rate : $rate;

        $results[] = [
            'pair'   => $label,
            'price'  => $price,
            'rate'   => $price,
            'usd'    => '$' . $price,
            'status' => 'ok',
        ];
    }

    return response()->json($results);
});

// General forex fallback API
Route::get('/forex/{symbol?}', function ($symbol = null) {

    try {
        $response = Http::timeout(6)->get("https://api.frankfurter.app/latest", [
            'from' => 'USD',
        ]);
    } catch (\Exception $e) {
        return ['success' => false, 'message' => 'Forex API error'];
    }

    if ($response->failed()) {
        return ['success' => false, 'message' => 'Forex API error'];
    }

    $json = $response->json();

    if (!isset($json['rates'])) {
        return ['success' => false, 'message' => 'Rates unavailable'];
    }

    $rates = $json['rates'];

    // Return all
    if (!$symbol) {
        return ['success' => true, 'data' => $rates];
    }

    $symbol = strtoupper($symbol);

    if (!isset($rates[$symbol])) {
        return ['success' => false, 'message' => "$symbol not found"];
    }

    return [
        'success' => true,
        'symbol' => $symbol,
        'rate' => $rates[$symbol]
    ];
});

// NowNodes Webhook
Route::post('/webhooks/nownodes', [NowNodesWebhookController::class, 'handle']);

// Staking plans (public)
Route::get('/staking-plans', [StakingPlanController::class, 'index']);
Route::get('/staking-plans/sync', [StakingPlanController::class, 'sync']);


// ========================================================================
// ====================  AUTHENTICATED ROUTES  ============================
// ========================================================================

Route::middleware('auth:sanctum')->group(function () {


    /*
    |--------------------------------------------------------------------------
    | USER ACCOUNT
    |--------------------------------------------------------------------------
    */

    Route::get('/user', [UserController::class, 'me']);
    Route::post('/return-to-admin', [UserController::class, 'returnToAdmin']);
    Route::post('/user', [UserController::class, 'update']);
    Route::post('/update/user', [UserController::class, 'update']); // (duplicate API kept for compatibility)
    Route::post('/user/change-password', [UserController::class, 'changePassword']);

    // 2FA
    Route::post('/user/2fa/enable', [UserController::class, 'enable2FA']);
    Route::post('/user/2fa/disable', [UserController::class, 'disable2FA']);

    Route::post('/withdraw/bank', [App\Http\Controllers\Api\WithdrawalController::class, 'bank']);
    Route::post('/withdraw/crypto', [App\Http\Controllers\Api\WithdrawalController::class, 'crypto']);

      /*
    |--------------------------------------------------------------------------
    | WALLET ROUTES
    |--------------------------------------------------------------------------
    */

    // List all user wallets
    Route::get('/portfolio/history', [PortfolioController::class, 'history']);

    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::get('/swap/quote', [SwapController::class, 'quote']);
    Route::post('/swap', [SwapController::class, 'swap']);

    Route::get('/wallets', [WalletController::class, 'index']);
    Route::get('/deposit-addresses', [DepositAddressController::class, 'index']);

    // Single wallet
    Route::get('/wallets/{wallet}', [WalletController::class, 'show'])
        ->whereNumber('wallet');

    // Wallets by mode + pair must come BEFORE /wallets/{mode}
    Route::get('/wallets/{mode}/{pair}', [WalletController::class, 'byModeAndPair']);

    // Wallets by trading mode (crypto, forex, futures)
    Route::get('/wallets/{mode}', [WalletController::class, 'byMode']);

        
     Route::get('/trades', [OrderController::class, 'myTrades']);
      
      
      
      
    /*  
    |--------------------------------------------------------------------------
    | POSITION ROUTES
    |--------------------------------------------------------------------------
    */

    // List open positions (optional mode filter: ?mode=forex)
    Route::get('/positions', [PositionController::class, 'index']);

    // Close a position (POST because we modify funds)
    Route::post('/positions/{id}/close', [PositionController::class, 'close'])
        ->whereNumber('id');


    /*
    |--------------------------------------------------------------------------
    | ORDER ROUTES
    |--------------------------------------------------------------------------
    */

    // Create order
    Route::post('/orders', [OrderController::class, 'store']);

    // Order list (if you have index)
    Route::get('/orders', [OrderController::class, 'index'])->middleware('auth:sanctum');

    // Update order
    Route::put('/orders/{id}', [OrderController::class, 'update'])
        ->whereNumber('id');

    // Delete order
    Route::delete('/orders/{id}', [OrderController::class, 'delete'])
        ->whereNumber('id');
       
      
    /*
    |--------------------------------------------------------------------------
    | KYC
    |--------------------------------------------------------------------------
    */

    Route::post('/kyc/face-verify', [KycController::class, 'verify']);
    Route::get('/kyc/status', [KycController::class, 'status']);


    /*
    |--------------------------------------------------------------------------
    | WALLET & LEDGER
    |--------------------------------------------------------------------------
    */

    
    // Spot
    Route::get('/user/wallet/spot', [SpotController::class, 'balance']);
    Route::get('/user/spot-orders/open', [SpotController::class, 'openOrders']);
    Route::get('/user/spot-trades/recent', [SpotController::class, 'recentTrades']);

    // Ledger
    Route::prefix('ledger')->group(function () {
        Route::get('/', [LedgerController::class, 'index']);
        Route::post('/deposit', [LedgerController::class, 'deposit']);
        Route::post('/withdraw', [LedgerController::class, 'withdraw']);
    });
 
 
        Route::post('/transfer/send', [TransferController::class, 'send']);
   
          Route::get('/process-earnings', [InvestmentPlanController::class, 'processEarnings']);
             
              
    /*
    |--------------------------------------------------------------------------
    | TRADING: MT5, SPOT, FUTURES
    |--------------------------------------------------------------------------
    */

    Route::prefix('mt5')->group(function () {
        Route::post('/trade', [TradeController::class, 'trade']);
        Route::get('/positions', [TradeController::class, 'positions'])->name('mt5.positions');
    });

    Route::get('/futures/positions', [FuturesController::class, 'positions']);

    Route::get('/futures/{symbol}', function ($symbol) {
        try {
            return Http::timeout(6)->get("https://api.binance-proxy.com/fapi/v1/ticker/price", ['symbol' => $symbol])->json();
        } catch (\Exception $e) {
            return response()->json(['error' => 'Futures price unavailable'], 503);
        }
    });


    /*
    |--------------------------------------------------------------------------
    | INVESTMENTS
    |--------------------------------------------------------------------------
    */

    Route::get('/ai-investments', [AiInvestmentController::class, 'index']);
    Route::post('/ai-investments', [AiInvestmentController::class, 'store']);
    Route::post('/ai-investments/{id}/complete', [AiInvestmentController::class, 'complete']);

    Route::get('/investment-payments', [InvestmentPaymentController::class, 'index']);
    Route::post('/investment-payments', [InvestmentPaymentController::class, 'store']);
    Route::post('/investment-payments/update', [InvestmentPaymentController::class, 'updateStatus']);

  Route::get('/investment-plans', [InvestmentPlanController::class, 'index']);
  Route::get('/investment-plans/sync', [InvestmentPlanController::class, 'sync']);
    
    Route::get('/investments', [InvestmentPlanController::class, 'userInvestments']);
    Route::post('/investments', [InvestmentPlanController::class, 'createInvestment']);
    Route::post('/investments/mark-paid', [InvestmentPlanController::class, 'markPaidPending']);
    Route::post('/investments/{id}/withdraw', [InvestmentPlanController::class, 'withdraw']);


    /*
    |--------------------------------------------------------------------------
    | STAKING
    |--------------------------------------------------------------------------
    */

    Route::get('/stakes', [UserStakeController::class, 'index']);
    Route::post('/stakes', [UserStakeController::class, 'store']);
    Route::post('/stakes/subscribe', [UserStakeController::class, 'subscribeUserStake']);
    Route::post('/stakes/claim', [UserStakeController::class, 'claim']);


    /*
    |--------------------------------------------------------------------------
    | ORDERS
    |--------------------------------------------------------------------------
    */

    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel']);


    /*
    |--------------------------------------------------------------------------
    | P2P
    |--------------------------------------------------------------------------
    */

    Route::get('/p2p/vendors', [P2PController::class, 'index']);
    Route::get('/p2p/vendors/sync', [P2PController::class, 'sync']);
    Route::post('/p2p/order', [P2PController::class, 'placeOrder']);
    Route::get('/p2p/orders', [P2PController::class, 'myOrders']);


    /*
    |--------------------------------------------------------------------------
    | REWARDS
    |--------------------------------------------------------------------------
    */

    Route::get('/rewards', [RewardController::class, 'index']);
    Route::post('/rewards/claim-all', [RewardController::class, 'claimAll']);
    Route::post('/rewards/claim/{id}', [RewardController::class, 'claim']);


    /*
    |--------------------------------------------------------------------------
    | WITHDRAWALS
    |--------------------------------------------------------------------------
    */

    


    /*
    |--------------------------------------------------------------------------
    | CHAT
    |--------------------------------------------------------------------------
    */

    Route::prefix('chat')->group(function () {
        Route::get('/{chatId}/messages', [ChatController::class, 'fetchMessages']);
        Route::post('/send', [ChatController::class, 'sendMessage']);
        Route::post('/chat/{chatId}/read', [ChatController::class, 'markAsRead']);
    });


    /*
    |--------------------------------------------------------------------------
    | NOTIFICATIONS + PUSH
    |--------------------------------------------------------------------------
    */

    Route::get('/notifications', [UserInteractionController::class, 'getNotifications']);
    Route::post('/notifications/{id}/read', [UserInteractionController::class, 'markNotificationRead']);

    Route::get('/push/vapid-public-key', [PushController::class, 'vapidPublicKey']);
    Route::post('/push/subscribe', [PushController::class, 'subscribe']);
    Route::post('/push/unsubscribe', [PushController::class, 'unsubscribe']);

       
       
       Route::post('/subscribe-with-code', [InvestmentPaymentController::class, 'subscribeWithCode']);
       
       
    /*
    |--------------------------------------------------------------------------
    | SELAR WEBHOOK (authenticated)
    |--------------------------------------------------------------------------
    */

    Route::post('/selar/webhook', [SelarWebhookController::class, 'handle']);
    
    // USER VERIFICATION ROUTES
    Route::get('/verification-codes', [UserVerificationController::class, 'getRequiredCodes']);
    Route::post('/verification-codes/submit', [UserVerificationController::class, 'submitCodes']);
    Route::get('/verification-codes/mine', [UserVerificationController::class, 'getMyCodes']);
});


// ========================================================================
// ==============================  ADMIN  =================================
// ========================================================================

Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */
    Route::get('stats', [AdminController::class, 'dashboardStats']);
    Route::get('chats', [ChatController::class, 'listConversations']);
    Route::post('chats/{userId}/resolve', [ChatController::class, 'resolveConversation']);
    Route::patch('users/{id}/balances', [AdminController::class, 'updateUserBalances']);
    /*
    |--------------------------------------------------------------------------
    | Users
    |--------------------------------------------------------------------------
    */
    Route::get('users', [AdminController::class, 'listUsers']);
    Route::get('users/active', [AdminController::class, 'listActiveUsers']);
    Route::get('users/{id}', [AdminController::class, 'getUser']);
    Route::put('users/{id}', [AdminController::class, 'updateUser']);
    Route::post('users/{id}/login-as', [AdminController::class, 'loginAsUser']);
    Route::delete('users/{id}', [AdminController::class, 'deleteUser']);

    // Wallet Actions
    Route::post('users/{id}/fund', [AdminController::class, 'fundUserWallet']);
    Route::post('wallets/recalculate', [AdminController::class, 'recalculateWalletValues']);
    Route::put('wallets/{walletId}', [AdminController::class, 'updateWalletBalance']);
    Route::post('users/{id}/wallet/adjust', [AdminController::class, 'adjustWallet']);

    /*
    |--------------------------------------------------------------------------
    | KYC
    |--------------------------------------------------------------------------
    */
    Route::get('kyc', [AdminController::class, 'listUsersKYC']);
    Route::post('kyc/{user_id}/approve', [AdminController::class, 'approveKYC']);
    Route::post('kyc/{user_id}/reject', [AdminController::class, 'rejectKYC']);

    /*
    |--------------------------------------------------------------------------
    | Investment Plans (CRUD)
    |--------------------------------------------------------------------------
    */
    Route::get('plans', [AdminController::class, 'listPlans']);
    Route::get('plans/{id}', [AdminController::class, 'getPlan']);
    Route::post('plans', [AdminController::class, 'createPlan']);
    Route::put('plans/{id}', [AdminController::class, 'updatePlan']);
    Route::delete('plans/{id}', [AdminController::class, 'deletePlan']);

    /*
    |--------------------------------------------------------------------------
    | Investment Payments (Manual / Admin Created)
    |--------------------------------------------------------------------------
    */  
    
Route::get('investments/pending', [AdminController::class, 'pendingInvestments']);
Route::get('user-stakes', [AdminController::class, 'listUserStakes']);
Route::post('investments/{id}/status', [AdminController::class, 'updateStatus']);
    Route::get('investments', [AdminController::class, 'listInvestmentPayments']);
    Route::get('investments/{id}', [AdminController::class, 'getInvestmentPayment']);
    Route::put('investments/{id}', [AdminController::class, 'updateInvestmentPayment']);
    Route::delete('investments/{id}', [AdminController::class, 'deleteInvestmentPayment']);

    /*
    |--------------------------------------------------------------------------
    | AI Investments
    |--------------------------------------------------------------------------
    */
    Route::get('ai-investments', [AiInvestmentController::class, 'index']);
    Route::get('ai-investments/{id}', [AiInvestmentController::class, 'show']);
    Route::post('ai-investments', [AiInvestmentController::class, 'store']);
    Route::put('ai-investments/{id}', [AiInvestmentController::class, 'update']);
    Route::post('ai-investments/{id}/complete', [AiInvestmentController::class, 'complete']);
    Route::delete('ai-investments/{id}', [AiInvestmentController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | Manual Investments (OLD)
    |--------------------------------------------------------------------------
    */
    Route::get('manual-investments', [AdminController::class, 'listManualInvestments']);
    Route::put('manual-investments/{id}', [AdminController::class, 'updateManualInvestment']);
    Route::delete('manual-investments/{id}', [AdminController::class, 'deleteManualInvestment']);

    /*
    |--------------------------------------------------------------------------
    | Futures
    |--------------------------------------------------------------------------
    */
    Route::get('futures/positions', [AdminController::class, 'listFuturesPositions']);
    Route::get('futures/balances', [AdminController::class, 'listFuturesBalances']);
    Route::put('futures/positions/{id}', [AdminController::class, 'updateFuturesPosition']);
    Route::delete('futures/positions/{id}', [AdminController::class, 'deleteFuturesPosition']);

    /*
    |--------------------------------------------------------------------------
    | Wallets
    |--------------------------------------------------------------------------
    */
    Route::get('wallets', [AdminController::class, 'listWallets']);
    Route::post('wallets/{user_id}/adjust', [AdminController::class, 'adjustWallet']);

    /*
    |--------------------------------------------------------------------------
    | Deposits
    |--------------------------------------------------------------------------
    */
    Route::get('deposits', [DepositController::class, 'list']);
    Route::get('deposit-addresses', [DepositAddressController::class, 'adminIndex']);
    Route::post('deposit-addresses', [DepositAddressController::class, 'store']);
    Route::put('deposit-addresses/{id}', [DepositAddressController::class, 'update']);
    Route::delete('deposit-addresses/{id}', [DepositAddressController::class, 'destroy']);
    Route::put('deposits/{id}/approve', [DepositController::class, 'approve']);
    Route::put('deposits/{id}/reject', [DepositController::class, 'reject']);

    /*
    |--------------------------------------------------------------------------
    | Withdrawals
    |--------------------------------------------------------------------------
    */
    Route::get('withdrawals', [App\Http\Controllers\WithdrawalController::class, 'list']);
    Route::put('withdrawals/{id}/approve', [App\Http\Controllers\WithdrawalController::class, 'approve']);
    Route::put('withdrawals/{id}/reject', [App\Http\Controllers\WithdrawalController::class, 'reject']);

    
   
    /*
    |--------------------------------------------------------------------------
    | P2P Vendors
    |--------------------------------------------------------------------------
    */
    Route::get('vendors', [AdminController::class, 'listVendors']);
    Route::post('vendors', [AdminController::class, 'createVendor']);
    Route::put('vendors/{id}', [AdminController::class, 'updateVendor']);
    Route::get('staking-plans', [AdminController::class, 'listStakingPlans']);
    Route::post('staking-plans', [AdminController::class, 'createStakingPlan']);
    Route::put('staking-plans/{id}', [AdminController::class, 'updateStakingPlan']);
    Route::delete('staking-plans/{id}', [AdminController::class, 'deleteStakingPlan']);
    Route::delete('vendors/{id}', [AdminController::class, 'deleteVendor']);
      
      
      
      Route::get('/subscription-codes', [AdminController::class, 'listSubscriptionCodes']);
    Route::post('/subscription-codes', [AdminController::class, 'createSubscriptionCode']);
    Route::put('/subscription-codes/{id}', [AdminController::class, 'updateSubscriptionCode']);
    Route::patch('/subscription-codes/{id}/toggle', [AdminController::class, 'toggleSubscriptionCode']);
    Route::delete('/subscription-codes/{id}', [AdminController::class, 'deleteSubscriptionCode']);
      
    /*
    |--------------------------------------------------------------------------
    | API Keys
    |--------------------------------------------------------------------------
    */
    Route::get('api', [AdminAPIController::class, 'list']);
    Route::post('api', [AdminAPIController::class, 'create']);
    Route::delete('api/{id}', [AdminAPIController::class, 'revoke']);

    /*
    |--------------------------------------------------------------------------
    | Security Logs
    |--------------------------------------------------------------------------
    */
    Route::get('security/logs', [SecurityController::class, 'listLogs']);

    /*
    |--------------------------------------------------------------------------
    | Settings
    |--------------------------------------------------------------------------
    */
    Route::get('settings', [SettingsController::class, 'view']);
    Route::put('settings', [SettingsController::class, 'update']);   
    
    
    
    // ADMIN VERIFICATION CODE ROUTES
    Route::get('verification-codes', [AdminController::class, 'listVerificationCodes']);
    Route::post('verification-codes', [AdminController::class, 'createVerificationCode']);
    Route::put('verification-codes/{id}', [AdminController::class, 'toggleVerificationCode']);
    Route::delete('verification-codes/{id}', [AdminController::class, 'deleteVerificationCode']);

    Route::get('verification-entries', [AdminController::class, 'listUserVerificationEntries']);
});