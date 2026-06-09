<?php

namespace App\Models;

use App\Enums\PlanAccess;
use Illuminate\Database\Eloquent\Model;

/**
 * Plantilla de documento (APA / Vancouver / custom) con settings JSON extensible para IA.
 */
class DocumentTemplate extends Model
{
    protected $fillable = [
        'name',
        'type',
        'status',
        'plan_access',
        'settings',
        'description',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'version' => 'integer',
            'plan_access' => PlanAccess::class,
        ];
    }
}
