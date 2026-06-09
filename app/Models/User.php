<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Enums\UserPlan;
use App\Services\SaaS\SubscriptionService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'plan',
        'subscription_status',
        'subscription_started_at',
        'subscription_expires_at',
        'subscription_notifications_sent',
        'trial_ends_at',
        'is_blocked',
        'registration_checkout_completed_at',
        'apa_settings',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'trial_ends_at' => 'datetime',
            'subscription_started_at' => 'datetime',
            'subscription_expires_at' => 'datetime',
            'subscription_notifications_sent' => 'array',
            'registration_checkout_completed_at' => 'datetime',
            'is_blocked' => 'boolean',
            'apa_settings' => 'array',
            'plan' => UserPlan::class,
            'subscription_status' => SubscriptionStatus::class,
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function hasActiveProSubscription(): bool
    {
        return app(SubscriptionService::class)->isProActive($this);
    }

    public function isProActive(): bool
    {
        return $this->hasActiveProSubscription();
    }
}
