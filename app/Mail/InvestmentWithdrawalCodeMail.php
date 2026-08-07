<?php

namespace App\Mail;

use App\Models\InvestmentPayment;
use App\Models\InvestmentWithdrawalVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvestmentWithdrawalCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public InvestmentPayment $investment,
        public InvestmentWithdrawalVerification $verification,
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
            markdown: 'emails.investment-withdrawal-code',
            with: [
                'code' => $this->verification->code,
                'label' => $this->verification->label,
                'explanation' => $this->verification->message,
                'planName' => $this->investment->plan_name,
                'amount' => number_format($this->investment->amount + $this->investment->expected_profit, 2),
            ],
        );
    }
}