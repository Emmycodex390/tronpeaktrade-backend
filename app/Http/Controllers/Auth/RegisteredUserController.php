<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Services\TatumService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Exception;

class RegisteredUserController extends Controller
{
    public function store(Request $request)
    {
        // Honeypot: a field the frontend keeps hidden from real users via
        // CSS. Bots that auto-fill every input on a form tend to fill it
        // anyway. Reject silently — no error detail, just make the bot
        // think it worked, so it doesn't adapt.
        if ($request->filled('website')) {
            return response()->json(['message' => 'Registration successful'], 201);
        }

        // ✅ Validate input
        $request->validate([
            'username' => ['required', 'string', 'max:50', 'unique:users,username'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'phone' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'id_type' => ['required', 'string', 'max:50'],
            'id_document' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);

        // ✅ Handle ID file upload
        $idPath = $request->file('id_document')->store('ids', 'public');

        // ✅ Create the user
        $user = User::create([
            'username' => $request->username,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'country' => $request->country,
            'address' => $request->address,
            'id_type' => $request->id_type,
            'id_document' => $idPath,
        ]);

        event(new Registered($user));

        $wallets = [];
        $walletErrors = [];

        // ✅ Define supported coins
        $coins = [
            ['symbol' => 'BTC', 'name' => 'Bitcoin', 'network' => 'Bitcoin'],
            ['symbol' => 'ETH', 'name' => 'Ethereum', 'network' => 'Ethereum'],
            ['symbol' => 'SOL', 'name' => 'Solana', 'network' => 'Solana'],
        ];

        // ✅ Generate wallet for each coin
   foreach ($coins as $coin) {
    try {
        $walletData = TatumService::createAddress($coin['symbol']);

        if (!isset($walletData['address'])) {
            throw new Exception("Tatum did not return an address for {$coin['symbol']}");
        }

        $wallet = Wallet::create([
            'user_id'   => $user->id,
            'coin'      => $coin['symbol'], // ✅ Added this line
            'name'      => $coin['name'],
            'symbol'    => $coin['symbol'],
            'network'   => $coin['network'],
            'address'   => $walletData['address'],
            'xpub'      => $walletData['xpub'] ?? null,
            'balance'   => 0,
            'usd_value' => 0,
        ]);

        $wallets[] = $wallet;
    } catch (Exception $e) {
        Log::error("Wallet creation failed for {$coin['symbol']}: " . $e->getMessage());
        $walletErrors[$coin['symbol']] = $e->getMessage();
    }
}

        // ✅ Auto-login new user
        Auth::login($user);

        \App\Services\PushService::notifyAdmins(
            'New user registered',
            "{$user->name} just signed up.",
            "/admin/users/{$user->id}"
        );

        // ✅ Return JSON response
        return response()->json([
            'message' => 'Registration successful',
            'user' => $user,
            'wallets' => $wallets,
            'wallet_errors' => $walletErrors,
        ], empty($walletErrors) ? 201 : 207); // 207 = Partial success
    }
}