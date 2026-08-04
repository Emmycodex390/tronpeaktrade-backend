<?php  
  
namespace App\Http\Controllers\Api;  
  
use App\Http\Controllers\Controller;  
use Illuminate\Http\Request;  
use Illuminate\Support\Facades\Auth;  
use Illuminate\Support\Facades\Hash;  
use Illuminate\Validation\ValidationException;  
use PragmaRX\Google2FA\Google2FA;  
  
class UserController extends Controller  
{  
    /**  
     * Get authenticated user info along with balances and wallets  
     */  
    public function me(Request $request)  
    {  
        $user = Auth::user();  
  
        $balances = [  
            'total_usdt' => (float) $user->total_usdt,  
            'conversion_ngn' => (float) $user->conversion_ngn,  
            'asset_balance' => (float) $user->asset_balance,  
            'investment_balance' => (float) $user->investment_balance,  
            'ai_investment_balance' => (float) $user->ai_investment_balance,  
        ];  
  
        return response()->json([  
            'user' => $user,  
            'balances' => $balances,  
            'wallets' => $user->wallets ?? [],  
            'impersonating' => session()->has('impersonator_id'),  
        ]);  
    }  

    // POST /api/return-to-admin — exits impersonation. Deliberately not
    // gated by the 'admin' middleware, since the currently-authenticated
    // user during impersonation is the target user (not an admin) — an
    // admin-only route would lock them out of ever leaving.
    public function returnToAdmin(Request $request)
    {
        $impersonatorId = session('impersonator_id');

        if (!$impersonatorId) {
            return response()->json(['error' => 'Not currently impersonating anyone'], 400);
        }

        $admin = \App\Models\User::findOrFail($impersonatorId);
        \Illuminate\Support\Facades\Auth::guard('web')->login($admin);
        session()->forget('impersonator_id');
        $request->session()->regenerate();

        return response()->json(['success' => true, 'user' => $admin]);
    }
  
  
    /**  
     * Update profile information including payment & avatar  
     */  
    public function update(Request $request)  
{  
    $user = Auth::user();  
  
    // VALIDATION (all optional)  
    $request->validate([  
        'username'       => 'sometimes|nullable|string|max:255',  
        'email'          => 'sometimes|nullable|email|max:255|unique:users,email,' . $user->id,  
        'phone'          => 'sometimes|nullable|string|max:20',  
        'address'        => 'sometimes|nullable|string|max:255',  
        'avatar'         => 'sometimes|nullable|image|max:5120',  
  
        // PAYMENT FIELDS  
        'bank_name'      => 'sometimes|nullable|string|max:255',  
        'account_name'   => 'sometimes|nullable|string|max:255',  
        'account_number' => 'sometimes|nullable|string|max:20',  
        'paypal_email'   => 'sometimes|nullable|email|max:255',  
    ]);  
  
    // UPDATE ONLY PROVIDED FIELDS  
    $updateData = $request->only([  
        'username',  
        'email',  
        'phone',  
        'address',  
        'bank_name',  
        'account_name',  
        'account_number',  
        'paypal_email',  
    ]);  
  
    $user->fill($updateData);  
  
    // HANDLE AVATAR UPLOAD  
    if ($request->hasFile('avatar')) {  
        $path = $request->file('avatar')->store('avatars', 'public');  
        $user->avatar = $path;  
    }  
  
    $user->save();  
  
    return response()->json([  
        'message' => 'Profile updated successfully',  
        'user' => $user,  
    ]);  
}  
  
  
    /**  
     * Change password  
     */  
    public function changePassword(Request $request)  
    {  
        $user = Auth::user();  
  
        $request->validate([  
            'current_password' => 'required|string',  
            'new_password'     => 'required|string|min:6|confirmed',  
        ]);  
  
        if (!Hash::check($request->current_password, $user->password)) {  
            throw ValidationException::withMessages([  
                'current_password' => ['Current password is incorrect.'],  
            ]);  
        }  
  
        $user->password = Hash::make($request->new_password);  
        $user->save();  
  
        return response()->json(['message' => 'Password updated successfully']);  
    }  
  
  
    /**  
     * Enable Google 2FA  
     */  
    public function enable2FA(Request $request)  
    {  
        $user = Auth::user();  
  
        $google2fa = new Google2FA();  
        $secret = $google2fa->generateSecretKey();  
  
        $user->two_factor_secret = $secret;  
        $user->two_factor_enabled = true;  
        $user->save();  
  
        return response()->json([  
            'message' => '2FA enabled successfully',  
            'secret'  => $secret,  
        ]);  
    }  
  
  
    /**  
     * Disable Google 2FA  
     */  
    public function disable2FA(Request $request)  
    {  
        $user = Auth::user();  
  
        $user->two_factor_secret = null;  
        $user->two_factor_enabled = false;  
        $user->save();  
  
        return response()->json(['message' => '2FA disabled successfully']);  
    }  
}