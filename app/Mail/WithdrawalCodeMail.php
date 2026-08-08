<?php

namespace App\Mail;

use App\Models\Withdrawal;
use App\Models\WithdrawalVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WithdrawalCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Withdrawal $withdrawal,
        public WithdrawalVerification $verification,
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
            markdown: 'emails.withdrawal-code',
            with: [
                'code' => $this->verification->code,
                'label' => $this->verification->label,
                'explanation' => $this->verification->message,
                'type' => $this->withdrawal->type,
                'amount' => number_format($this->withdrawal->amount, 2),
            ],
        );
    }
}