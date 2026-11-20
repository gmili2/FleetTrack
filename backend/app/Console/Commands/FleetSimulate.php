<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Tracking\ShiftService;
use App\Services\Tracking\StatusMonitor;
use App\Simulation\Simulator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FleetSimulate extends Command
{
    protected $signature = 'fleet:simulate
        {--interval=5 : Secondes entre deux positions}
        {--speed=1 : Accélération du temps simulé (2 = trajets et arrêts deux fois plus courts)}
        {--ticks=0 : Nombre de pas puis arrêt (0 = en continu)}
        {--paused : Démarrer en pause (reprise depuis Paramètres)}';

    protected $description = 'Simulateur GPS : fait rouler les camions virtuels autour de Casablanca';

    private bool $stop = false;

    public function handle(Simulator $sim, StatusMonitor $monitor, ShiftService $shifts): int
    {
        DB::connection()->disableQueryLog();
        $sim->interval = max(1, (int) $this->option('interval'));
        $sim->speedFactor = max(0.1, (float) $this->option('speed'));
        $sim->onLog(fn (string $m) => $this->line('<fg=gray>'.now('Africa/Casablanca')->format('H:i:s').'</> '.$m));
        $sim->boot();

        Setting::put('simulator.running', ! $this->option('paused'));
        $this->trap([SIGINT, SIGTERM], fn () => $this->stop = true);
        $this->info("Simulateur lancé : une position toutes les {$sim->interval} s, temps x{$sim->speedFactor}. Ctrl+C pour arrêter.");

        $maxTicks = (int) $this->option('ticks');
        $n = 0;
        while (! $this->stop && ($maxTicks === 0 || $n < $maxTicks)) {
            $started = microtime(true);
            Setting::put('simulator.heartbeat_at', now()->toIso8601String());

            if (Setting::get('simulator.running', true)) {
                $sim->tick(CarbonImmutable::now());
                $n++;
                if ($n % 6 === 0) {
                    $monitor->markOffline();
                    $shifts->flagUnbadgedExits();
                }
            }

            if ($maxTicks === 0 || $n < $maxTicks) {
                usleep((int) max(0, ($sim->interval - (microtime(true) - $started)) * 1e6));
            }
        }

        Setting::put('simulator.heartbeat_at', null);
        $this->info('Simulateur arrêté.');

        return self::SUCCESS;
    }
}
