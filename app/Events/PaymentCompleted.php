<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user,
        public string $payer_name,
        public float $amount,
        public string $invoice_number,
        public ?string $link = null,
    ) {}
}
