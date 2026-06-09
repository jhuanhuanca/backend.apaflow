<?php

namespace App\Models;

use App\Enums\PlanAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class University extends Model
{
    protected $fillable = [
        'name',
        'country',
        'logo_path',
        'institution_type',
        'status',
        'tenant_id',
        'plan_access',
    ];

    protected function casts(): array
    {
        return [
            'plan_access' => PlanAccess::class,
        ];
    }

    public function careers(): HasMany
    {
        return $this->hasMany(Career::class);
    }

    public function contentCategories(): HasMany
    {
        return $this->hasMany(ContentCategory::class);
    }
}
