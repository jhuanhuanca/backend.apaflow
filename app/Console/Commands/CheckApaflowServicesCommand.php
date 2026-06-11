<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class CheckApaflowServicesCommand extends Command
{
    protected $signature = 'apaflow:check-services';

    protected $description = 'Comprueba Python, storage y Redis (diagnóstico producción)';

    public function handle(): int
    {
        $ok = true;

        $pythonUrl = rtrim((string) config('services.python.url'), '/');
        $this->line("Python URL (Laravel): {$pythonUrl}");

        try {
            $health = Http::timeout(5)->get("{$pythonUrl}/health");
            if ($health->successful()) {
                $this->info('Python /health: OK');
            } else {
                $ok = false;
                $this->error("Python /health: HTTP {$health->status()}");
            }
        } catch (\Throwable $e) {
            $ok = false;
            $this->error('Python /health: '.$e->getMessage());
            $this->warn('¿Python en otro puerto? curl http://127.0.0.1:8000/health vs 8001. Debe coincidir con PYTHON_SERVICE_URL.');
        }

        $apiKey = config('services.python.api_key');
        if (is_string($apiKey) && $apiKey !== '') {
            $this->info('PYTHON_SERVICE_API_KEY: configurada');
        } else {
            $this->warn('PYTHON_SERVICE_API_KEY: vacía (Python en AI_ENV=production la exige).');
        }

        $disk = Storage::disk('local');
        $root = (string) config('filesystems.disks.local.root');
        $this->line("Storage local root: {$root}");

        $probe = 'documents/.health-probe';
        try {
            if (! $disk->put($probe, 'ok')) {
                throw new \RuntimeException('put() devolvió false (revisa permisos de storage/app/private).');
            }
            if (! $disk->exists($probe)) {
                throw new \RuntimeException('El archivo de prueba no existe tras guardarlo.');
            }
            $disk->delete($probe);
            $this->info('Storage local (private): escribible');
        } catch (\Throwable $e) {
            $ok = false;
            $this->error('Storage local: '.$e->getMessage());
            $this->warn('En el servidor: sudo mkdir -p storage/app/private/documents && sudo chown -R www-data:www-data storage bootstrap/cache');
        }

        try {
            Redis::connection()->ping();
            $this->info('Redis: OK');
        } catch (\Throwable $e) {
            $ok = false;
            $this->error('Redis: '.$e->getMessage());
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
