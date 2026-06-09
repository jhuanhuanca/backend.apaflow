<?php

namespace Database\Seeders;

use App\Enums\AdminRoleName;
use App\Enums\PlanAccess;
use App\Models\Admin;
use App\Models\Career;
use App\Models\DocumentTemplate;
use App\Models\Role;
use App\Models\University;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SaasFoundationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (AdminRoleName::cases() as $case) {
            Role::query()->firstOrCreate(
                ['name' => $case->value],
                ['guard_name' => 'admin'],
            );
        }

        $password = env('ADMIN_SEED_PASSWORD', 'ChangeMe!Admin123');

        $admin = Admin::query()->firstOrCreate(
            ['email' => env('ADMIN_SEED_EMAIL', 'admin@example.com')],
            [
                'name' => 'Super Admin',
                'password' => Hash::make($password),
                'is_active' => true,
            ],
        );

        $super = Role::query()->where('name', AdminRoleName::Superadmin->value)->first();
        if ($super && ! $admin->roles()->whereKey($super->id)->exists()) {
            $admin->roles()->attach($super->id);
        }

        $apaSettings = [
            'margins_cm' => ['top' => 2.5, 'bottom' => 2.5, 'left' => 4, 'right' => 2.5],
            'line_height' => 1.5,
            'paragraph_indent' => 'first_line_1.27cm',
            'citations' => 'apa7_author_date',
            'tables' => 'three_rules',
            'figures' => 'numbered_below_caption',
            'tentative_index' => true,
        ];

        $vancouverSettings = [
            'margins_cm' => ['top' => 2.5, 'bottom' => 2.5, 'left' => 2.5, 'right' => 2.5],
            'line_height' => 1.5,
            'citations' => 'vancouver_numbered',
            'tables' => 'journal_style',
            'figures' => 'numbered_below_caption',
            'tentative_index' => true,
        ];

        $apa = DocumentTemplate::query()->updateOrCreate(
            ['type' => 'apa'],
            [
                'name' => 'APA 7',
                'status' => 'active',
                'version' => 1,
                'description' => 'Plantilla base APA 7.',
                'plan_access' => PlanAccess::Both,
                'settings' => $apaSettings,
            ],
        );

        DocumentTemplate::query()->updateOrCreate(
            ['type' => 'vancouver'],
            [
                'name' => 'Vancouver',
                'status' => 'active',
                'version' => 1,
                'description' => 'Plantilla base Vancouver.',
                'plan_access' => PlanAccess::Both,
                'settings' => $vancouverSettings,
            ],
        );

        DocumentTemplate::query()->firstOrCreate(
            ['type' => 'custom', 'name' => 'Personalizada (demo)'],
            [
                'name' => 'Personalizada (demo)',
                'status' => 'draft',
                'version' => 1,
                'description' => 'Plantilla custom con settings JSON extensibles (IA / motor futuro).',
                'plan_access' => PlanAccess::Pro,
                'settings' => [
                    'margins_cm' => ['top' => 2.5, 'bottom' => 2.5, 'left' => 3, 'right' => 2.5],
                    'line_height' => 1.5,
                    'paragraph_indent' => 'configurable',
                    'citations' => 'custom',
                    'tables' => 'custom',
                    'figures' => 'custom',
                    'tentative_index' => true,
                ],
            ],
        );

        $uni = University::query()->firstOrCreate(
            ['name' => 'Universidad Demo SaaS'],
            [
                'country' => 'ES',
                'institution_type' => 'public',
                'status' => 'active',
                'plan_access' => PlanAccess::Both,
            ],
        );

        Career::query()->firstOrCreate(
            [
                'university_id' => $uni->id,
                'name' => 'Psicología (catálogo demo)',
            ],
            [
                'status' => 'active',
                'plan_access' => PlanAccess::Both,
                'category' => 'Ciencias sociales',
                'description' => 'Carrera de ejemplo para el flujo de selección y plantilla APA.',
                'document_template_id' => $apa->id,
            ],
        );

        Career::query()->firstOrCreate(
            [
                'university_id' => $uni->id,
                'name' => 'Medicina avanzada (solo Pro)',
            ],
            [
                'status' => 'active',
                'plan_access' => PlanAccess::Pro,
                'category' => 'Salud',
                'description' => 'Ejemplo de carrera visible solo para planes Pro / Trial.',
                'document_template_id' => $apa->id,
            ],
        );
    }
}
