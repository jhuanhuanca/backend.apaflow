<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdPlacement extends Model
{
    protected $fillable = [
        'name',
        'location',
        'format',
        'status',
        'priority',
        'audience',
        'provider',
        'slot_id',
        'label',
        'starts_at',
        'ends_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'metadata' => 'array',
            'priority' => 'integer',
        ];
    }
}
