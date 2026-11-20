<?php

namespace App\Console\Commands;

use App\Services\Tracking\DailyStatsService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class FleetDailyStats extends Command
{
    protected $signature = 'fleet:daily-stats {--date= : Jour à calculer (AAAA-MM-JJ), par défaut hier}';

    protected $description = 'Calcule les statistiques journalières des camions';

    public function handle(DailyStatsService $service): int
    {
        $date = CarbonImmutable::parse($this->option('date') ?? CarbonImmutable::now(DailyStatsService::TZ)->subDay()->toDateString(), DailyStatsService::TZ);
        $n = $service->compute($date);
        $this->info("Statistiques du {$date->format('d/m/Y')} : $n camion(s).");

        return self::SUCCESS;
    }
}
