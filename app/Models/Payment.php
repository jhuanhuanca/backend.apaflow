<?php

namespace App\Models;

use App\Enums\PaymentChannel;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = [
        'user_id',
        'document_id',
        'amount_cents',
        'currency',
        'status',
        'provider',
        'channel',
        'external_id',
        'metadata',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'paid_at' => 'datetime',
            'channel' => PaymentChannel::class,
            'status' => PaymentStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
