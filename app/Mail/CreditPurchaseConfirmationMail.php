<?php

namespace App\Mail;

use App\Models\User;
use App\Support\FrontendUrl;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CreditPurchaseConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $package_name,
        public int $credits_amount,
        public float $amount_paid,
        public int $new_balance,
        public Carbon $purchase_date,
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            to: $this->user->email,
            subject: 'Your credits have been added — ' . number_format($this->credits_amount) . ' credits are now available',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.credits.purchase-confirmation',
            with: [
                'user_first_name'  => $this->user->first_name,
                'user_last_name'   => $this->user->last_name,
                'package_name'     => $this->package_name,
                'credits_amount'   => $this->credits_amount,
                'amount_paid'      => $this->amount_paid,
                'new_balance'      => $this->new_balance,
                'purchase_date'    => $this->purchase_date->format('F j, Y \a\t g:i A'),
                'app_name'         => config('app.name'),
                'frontend_url'     => FrontendUrl::to(),
            ],
        );
    }
}
