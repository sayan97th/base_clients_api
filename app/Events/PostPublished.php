<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PostPublished
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user,
        public string $service_name,
        public ?string $preview_text = null,
        public ?string $link = null,
    ) {}
}
