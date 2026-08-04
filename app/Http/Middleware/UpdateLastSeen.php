<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class UpdateLastSeen
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user) {
            // Only write if it's been a while — avoids a DB write on
            // every single API call, which would be wasteful given how
            // often authenticated requests fire (polling, etc.).
            if (!$user->last_seen_at || $user->last_seen_at->diffInSeconds(now()) > 60) {
                $user->timestamps = false;
                $user->update(['last_seen_at' => now()]);
            }
        }

        return $next($request);
    }
}
