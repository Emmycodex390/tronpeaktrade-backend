<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\PushService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    /**
     * Handle an incoming authentication request.
     *
     * Token-based now, not session-based — we deliberately don't touch
     * Auth::attempt()/session() here, since this route runs stateless
     * (no session middleware). We verify credentials directly and hand
     * back a Sanctum personal access token instead of a session cookie.
     * This is what makes auth work across different domains (frontend on
     * Vercel, API on Render) without depending on third-party cookies,
     * which Chrome/Firefox block by default regardless of how correct
     * the CORS/cookie config is.
     */
    public function store(LoginRequest $request)
    {
        $request->ensureIsNotRateLimited();

        $user = User::where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            RateLimiter::hit($request->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($request->throttleKey());

        if ($user->status === 'banned') {
            abort(403, 'This account has been suspended. Contact support for help.');
        }

        if ($user->role !== 'admin') {
            PushService::notifyAdmins(
                'User logged in',
                "{$user->name} just signed in.",
                "/admin/users/{$user->id}"
            );
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Revoke the token used to make this request.
     */
    public function destroy(Request $request): Response
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->noContent();
    }
}
