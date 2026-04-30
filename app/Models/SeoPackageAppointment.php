<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoPackageAppointment extends Model
{
    protected $fillable = [
        'user_id',
        'seo_package_id',
        'status',
        'scheduled_at',
        'event_uri',
        'invitee_uri',
        'notes',
        'admin_notes',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(SeoPackage::class, 'seo_package_id');
    }
}
