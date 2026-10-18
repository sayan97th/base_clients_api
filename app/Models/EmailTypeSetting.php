<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTypeSetting extends Model
{
    protected $fillable = [
        'email_type',
        'is_enabled',
    ];

    protected $attributes = [
        'is_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }
}
