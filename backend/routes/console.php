<?php

use Illuminate\Support\Facades\Schedule;

// Statuts « hors ligne » et sorties non pointées, même si le simulateur est arrêté.
Schedule::command('fleet:check-status')->everyMinute()->withoutOverlapping();
