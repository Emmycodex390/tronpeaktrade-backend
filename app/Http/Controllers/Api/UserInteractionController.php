<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class UserInteractionController extends Controller
{
    /**
     * GET /api/notifications
     *
     * The User model already uses Laravel's Notifiable trait, so this
     * just reads from the standard notifications table rather than
     * needing a custom model. This method didn't exist at all before,
     * which is why every call to /api/notifications was throwing a
     * BadMethodCallException.
     */
    public function getNotifications(Request $request)
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $notifications,
        ]);
    }

    /**
     * POST /api/notifications/{id}/read
     */
    public function markNotificationRead(Request $request, $id)
    {
        $notification = $request->user()
            ->notifications()
            ->where('id', $id)
            ->firstOrFail();

        $notification->markAsRead();

        return response()->json(['success' => true]);
    }
}
