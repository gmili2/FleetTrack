<?php

namespace App\Jobs;

use App\Services\Tracking\DailyStatsService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Job nocturne : statistiques de la veille (planifié à 00:15, heure de Casablanca). */
class ComputeDailyStats implements ShouldQueue
{
    use Queueable;

    public function __construct(public ?string $date = null) {}

    public function handle(DailyStatsService $service): void
    {
        $service->compute(CarbonImmutable::parse($this->date ?? CarbonImmutable::now(DailyStatsService::TZ)->subDay()->toDateString(), DailyStatsService::TZ));
    }
}
