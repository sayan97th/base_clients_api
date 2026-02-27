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
        public string $post_title,
        public ?string $preview_text = null,
        public ?string $link = null,
    ) {}
}
