<?php

namespace App\Events;

use App\Models\LinkBuildingOrder;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LinkBuildingOrderPlaced
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user,
        public LinkBuildingOrder $order,
        public int $total_links,
    ) {}
}
