<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class NewDeviceLoginNotification extends Notification implements ShouldQueue
{
    use Queueable;
    protected $ip;
    protected $device;

    public function __construct($ip, $device)
    {
        $this->ip = $ip;
        $this->device = $device;
    }

    public function via($notifiable)
    {
        $prefs = $notifiable->notificationPreference;
        $channels = [];
        if ($prefs?->email) $channels[] = 'mail';
        if ($prefs?->push) $channels[] = 'database';
        if ($prefs?->security) $channels[] = 'database';
        return $channels;
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
                    ->subject('New Login Detected')
                    ->line("Your account was accessed from a new device: {$this->device}, IP: {$this->ip}")
                    ->line('If this wasn’t you, please secure your account immediately.');
    }

    public function toArray($notifiable)
    {
        return [
            'type' => 'security',
            'message' => "New login from device {$this->device}, IP: {$this->ip}",
        ];
    }
}