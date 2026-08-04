<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class PasswordChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via($notifiable)
    {
        $prefs = $notifiable->notificationPreference;
        $channels = [];
        if ($prefs?->email) $channels[] = 'mail';
        if ($prefs?->push) $channels[] = 'database'; // we will handle push later
        return $channels;
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
                    ->subject('Password Changed')
                    ->line('Your account password has been updated successfully.')
                    ->line('If this wasn’t you, please contact support immediately.');
    }

    public function toArray($notifiable)
    {
        return [
            'type' => 'security',
            'message' => 'Your password was changed successfully.',
        ];
    }
}