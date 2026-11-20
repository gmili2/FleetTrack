<?php

use Illuminate\Support\Facades\Schedule;

// Statuts « hors ligne » et sorties non pointées, même si le simulateur est arrêté.
Schedule::command('fleet:check-status')->everyMinute()->withoutOverlapping();

// Statistiques journalières de la veille, chaque nuit.
Schedule::job(new \App\Jobs\ComputeDailyStats)->dailyAt('00:15')->timezone('Africa/Casablanca');
