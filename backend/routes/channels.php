<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (string) $user->id === (string) $id;
});

// Canal privé de la flotte : positions, statuts, badges, visites de zones.
Broadcast::channel('fleet', fn ($user) => $user->can('tracking.view'));
