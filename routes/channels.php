<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Fleet delivery private channel authorization
Broadcast::channel('fleet-delivery.{schoolId}', function ($user, $schoolId) {
    if (! $user) return false;
    // allow drivers and admins from the same school to listen
    return isset($user->school_id) && (int)$user->school_id === (int)$schoolId;
});
