<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SystemAlert
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user,
        public string $message,
        public ?string $link = null,
    ) {}
}
