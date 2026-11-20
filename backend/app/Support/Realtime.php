<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/** Diffusion temps réel tolérante : une panne de Reverb ne doit jamais bloquer le suivi GPS. */
final class Realtime
{
    private static int $lastWarning = 0;

    public static function send(object $event): void
    {
        try {
            broadcast($event);
        } catch (Throwable $e) {
            if (time() - self::$lastWarning > 60) {
                self::$lastWarning = time();
                Log::warning('Diffusion temps réel impossible (Reverb est-il lancé ?) : '.$e->getMessage());
            }
        }
    }
}
