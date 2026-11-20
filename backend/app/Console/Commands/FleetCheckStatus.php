<?php

namespace App\Console\Commands;

use App\Services\Tracking\ShiftService;
use App\Services\Tracking\StatusMonitor;
use Illuminate\Console\Command;

class FleetCheckStatus extends Command
{
    protected $signature = 'fleet:check-status';

    protected $description = 'Passe hors ligne les camions silencieux et signale les sorties non pointées';

    public function handle(StatusMonitor $monitor, ShiftService $shifts): int
    {
        $offline = $monitor->markOffline();
        $flagged = $shifts->flagUnbadgedExits();
        $this->info("$offline camion(s) passé(s) hors ligne, $flagged sortie(s) non pointée(s).");

        return self::SUCCESS;
    }
}
