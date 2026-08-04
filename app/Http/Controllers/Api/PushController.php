<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\Request;

class PushController extends Controller
{
    /**
     * GET /api/push/vapid-public-key — the frontend needs this to call
     * pushManager.subscribe(). Not secret, safe to expose.
     */
    public function vapidPublicKey()
    {
        $key = config('services.vapid.public_key');
        if (!$key) {
            return response()->json(['error' => 'Push notifications are not configured yet.'], 503);
        }
        return response()->json(['public_key' => $key]);
    }

    /**
     * POST /api/push/subscribe
     */
    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint' => 'required|string',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
        ]);

        PushSubscription::updateOrCreate(
            ['user_id' => $request->user()->id, 'endpoint' => $data['endpoint']],
            ['public_key' => $data['keys']['p256dh'], 'auth_token' => $data['keys']['auth']]
        );

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/push/unsubscribe
     */
    public function unsubscribe(Request $request)
    {
        $data = $request->validate(['endpoint' => 'required|string']);

        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint', $data['endpoint'])
            ->delete();

        return response()->json(['success' => true]);
    }
}
