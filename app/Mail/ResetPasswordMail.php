<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $token,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your TronPeak Trade password',
        );
    }

    public function content(): Content
    {
        // Matches password-reset.tsx's actual route exactly:
        // route("password-reset/:token", "routes/password-reset.tsx"),
        // which reads the token from the path and email from a query
        // param via useParams()/useSearchParams().
        $url = rtrim(config('app.frontend_url'), '/')
            . '/password-reset/' . $this->token
            . '?email=' . urlencode($this->user->email);

        return new Content(
            markdown: 'emails.reset-password',
            with: [
                'url' => $url,
                'expiresMinutes' => (int) config('auth.passwords.users.expire', 60),
            ],
        );
    }
}