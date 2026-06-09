<?php

namespace App\Models;

use App\Enums\DocumentBillingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'career_id',
        'guest_fingerprint',
        'original_file',
        'processed_file',
        'status',
        'billing_status',
        'payment_id',
        'university',
        'career',
    ];

    protected function casts(): array
    {
        return [
            'billing_status' => DocumentBillingStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function selectedCareer(): BelongsTo
    {
        return $this->belongsTo(Career::class, 'career_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(DocumentLog::class)->orderBy('created_at');
    }

    public function addLog(string $message): DocumentLog
    {
        return $this->logs()->create(['message' => $message]);
    }
}
