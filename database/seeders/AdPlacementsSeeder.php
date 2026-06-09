<?php

namespace Database\Seeders;

use App\Models\AdPlacement;
use Illuminate\Database\Seeder;

class AdPlacementsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['name' => 'Home — banner superior', 'location' => 'home_top', 'format' => 'banner', 'priority' => 10, 'audience' => 'non_pro', 'label' => 'Espacio publicitario'],
            ['name' => 'Home — banner intermedio', 'location' => 'home_mid', 'format' => 'banner', 'priority' => 20, 'audience' => 'non_pro', 'label' => 'Anuncio patrocinado'],
            ['name' => 'Home — pre footer', 'location' => 'home_pre_footer', 'format' => 'footer', 'priority' => 30, 'audience' => 'non_pro', 'label' => 'Publicidad'],
            ['name' => 'Guía — superior', 'location' => 'guide_top', 'format' => 'banner', 'priority' => 10, 'audience' => 'non_pro', 'label' => 'Espacio publicitario'],
            ['name' => 'Guía — inline', 'location' => 'guide_inline', 'format' => 'inline', 'priority' => 20, 'audience' => 'non_pro', 'label' => 'Anuncio patrocinado'],
            ['name' => 'Guía — inferior', 'location' => 'guide_bottom', 'format' => 'banner', 'priority' => 30, 'audience' => 'non_pro', 'label' => 'Publicidad'],
            ['name' => 'Sidebar guías', 'location' => 'guide_sidebar', 'format' => 'sidebar', 'priority' => 40, 'audience' => 'non_pro', 'label' => 'Espacio publicitario'],
        ];

        foreach ($rows as $row) {
            AdPlacement::query()->firstOrCreate(
                ['location' => $row['location'], 'format' => $row['format'], 'name' => $row['name']],
                array_merge($row, ['status' => 'active', 'provider' => 'placeholder'])
            );
        }
    }
}
