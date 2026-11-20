<?php

namespace App\Console\Commands;

use App\Services\Tracking\DailyStatsService;
use App\Simulation\HistoryGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FleetSeedHistory extends Command
{
    protected $signature = 'fleet:seed-history {--days=30 : Nombre de jours d’historique avant aujourd’hui}';

    protected $description = 'Génère un historique cohérent (trajets, visites, pointages, missions) puis les statistiques journalières';

    public function handle(HistoryGenerator $generator, DailyStatsService $stats): int
    {
        DB::connection()->disableQueryLog();
        mt_srand(20261117); // historique reproductible
        $days = max(1, min(120, (int) $this->option('days')));
        $this->info("Génération de $days jours d’historique…");
        $generator->onLog(fn ($m) => $this->line("  $m"));
        $started = microtime(true);
        $r = $generator->run($days);

        $this->info('Calcul des statistiques journalières…');
        $today = CarbonImmutable::now(DailyStatsService::TZ)->startOfDay();
        for ($d = $today->subDays($days); $d->lt($today); $d = $d->addDay()) {
            $stats->compute($d);
        }

        $this->info(sprintf('Terminé en %d s : %d services, %s positions, %d missions historiques.',
            microtime(true) - $started, $r['shifts'], number_format($r['positions'], 0, ',', ' '), $r['missions']));

        return self::SUCCESS;
    }
}
