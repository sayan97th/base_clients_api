<?php

namespace App\Mail;

use App\Models\LinkBuildingOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<string, mixed> $report_data  Pre-assembled report payload (from buildReportResponse)
     */
    public function __construct(
        public array $report_data,
        public LinkBuildingOrder $order,
        public ?string $custom_message = null,
    ) {}

    public function envelope(): Envelope
    {
        $app_name = config('app.name');

        return new Envelope(
            subject: "Your Link Building Report — {$this->order->order_title} | {$app_name}",
        );
    }

    public function content(): Content
    {
        $client    = $this->order->user;
        $user_name = trim("{$client->first_name} {$client->last_name}");
        $app_name  = config('app.name');
        $order_url = config('app.frontend_url') . '/orders/' . $this->order->id;

        return new Content(
            view: 'emails.order-report',
            with: [
                'report_data'    => $this->report_data,
                'order'          => $this->order,
                'custom_message' => $this->custom_message,
                'user_name'      => $user_name,
                'user_email'     => $client->email,
                'app_name'       => $app_name,
                'order_url'      => $order_url,
            ],
        );
    }
}
