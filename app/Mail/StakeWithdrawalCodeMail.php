<?php

namespace App\Mail;

use App\Models\UserStake;
use App\Models\StakeWithdrawalVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StakeWithdrawalCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public UserStake $stake,
        public StakeWithdrawalVerification $verification,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your TronPeak Trade withdrawal confirmation code',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.stake-withdrawal-code',
            with: [
                'code' => $this->verification->code,
                'label' => $this->verification->label,
                'coin' => $this->stake->coin,
                'amount' => number_format($this->stake->amount, 6),
            ],
        );
    }
}
