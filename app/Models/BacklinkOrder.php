<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacklinkOrder extends Model
{
    use HasUuids;

    protected $table = 'backlink_orders';

    protected $fillable = [
        'order_id',
        'team_specific_link_id',
        'link_type',
        'client',
        'keyword',
        'landing_page',
        'exact_match',
        'notes',
        'request_date',
        'estimated_delivery_date',
        'estimated_turnaround_days',
        'link_builder_user_id',
        'link_builder',
        'pen_name',
        'partnership',
        'article_title',
        'article',
        'status',
        'live_link',
        'live_link_date',
        'dr_lbs',
        'posting_fee_lbs',
        'current_traffic',
        'dr_formula',
        'current_poc',
        'current_price',
        'lb_tl_approval',
        'approval_date',
        'final_price',
    ];

    public function linkBuilderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'link_builder_user_id');
    }
}
