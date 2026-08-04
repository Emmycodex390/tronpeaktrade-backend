<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class PushService
{
    /**
     * Sends a push notification to every admin's subscribed device(s).
     * Silently drops any subscription that has expired or been revoked
     * (the browser/OS returns 404/410 for those) — that's normal churn,
     * not something to surface as an error.
     */
    public static function notifyAdmins(string $title, string $body, ?string $url = null, array $extraData = []): void
    {
        $adminIds = User::where('role', 'admin')->pluck('id');
        $subscriptions = PushSubscription::whereIn('user_id', $adminIds)->get();

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

        foreach ($webPush->flush() as $report) {
            if (!$report->isSuccess() && $report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $report->getRequest()->getUri())->delete();
            }
        }
    }
}
