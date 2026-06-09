<?php

namespace App\Models;

use App\Enums\PlanAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Career extends Model
{
    protected $fillable = [
        'university_id',
        'content_category_id',
        'document_template_id',
        'name',
        'category',
        'description',
        'logo_path',
        'status',
        'plan_access',
    ];

    protected function casts(): array
    {
        return [
            'plan_access' => PlanAccess::class,
        ];
    }

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }

    public function contentCategory(): BelongsTo
    {
        return $this->belongsTo(ContentCategory::class);
    }

    public function documentTemplate(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class);
    }
}
