<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\AdminAlertNotification;
use Illuminate\Support\Facades\Notification;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class PushService
{
    /**
     * Sends a push notification to every admin's subscribed device(s),
     * AND creates an in-app (database) notification for every admin
     * regardless of whether they have push set up — that's what
     * actually shows up in the admin notification bell. Previously
     * this only did push, which meant admins without push configured
     * (or who'd never granted permission) never saw these at all.
     */
    public static function notifyAdmins(string $title, string $body, ?string $url = null, array $extraData = []): void
    {
        $admins = User::where('role', 'admin')->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new AdminAlertNotification($title, $body, $url));
        }

        $subscriptions = PushSubscription::whereIn('user_id', $admins->pluck('id'))->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        self::send($subscriptions, $title, $body, $url, $extraData);
    }

    private static function send($subscriptions, string $title, string $body, ?string $url, array $extraData = []): void
    {
        $publicKey = config('services.vapid.public_key');
        $privateKey = config('services.vapid.private_key');
        $subject = config('services.vapid.subject', 'mailto:support@fexistrade.com');

        if (!$publicKey || !$privateKey) {
            // VAPID keys not configured — see setup notes. Fail quietly
            // rather than breaking whatever action triggered this (a
            // chat message, a login) just because push isn't set up yet.
            return;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ]);

        $payload = json_encode(array_merge([
            'title' => $title,
            'body' => $body,
            'url' => $url ?? '/admin',
        ], $extraData));

        foreach ($subscriptions as $sub) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->public_key,
                    'authToken' => $sub->auth_token,
                ]),
                $payload
            );
        }

        try {
            foreach ($webPush->flush() as $report) {
                if (!$report->isSuccess() && $report->isSubscriptionExpired()) {
                    PushSubscription::where('endpoint', $report->getRequest()->getUri())->delete();
                }
            }
        } catch (\Exception $e) {
            // Never let a push-delivery failure turn an already-saved
            // chat message into an apparent error for the sender — the
            // message itself succeeded well before this ever runs.
            \Illuminate\Support\Facades\Log::warning('Push notification send failed', ['error' => $e->getMessage()]);
        }
    }
}
