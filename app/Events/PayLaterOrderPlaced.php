<?php

namespace App\Events;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PayLaterOrderPlaced
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user,
        public string $client_name,
        public float $amount,
        public string $invoice_number,
        public ?string $link = null,
        public ?Invoice $invoice = null,
    ) {}
}
