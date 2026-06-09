<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentCategory extends Model
{
    protected $fillable = [
        'university_id',
        'parent_id',
        'name',
        'slug',
        'description',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ContentCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ContentCategory::class, 'parent_id');
    }
}
