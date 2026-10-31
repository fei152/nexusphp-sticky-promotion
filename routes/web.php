<?php

use Illuminate\Support\Facades\Route;
use NexusPlugin\StickyPromotion\Controller;

Route::middleware(['web', 'auth.nexus:nexus-web'])->prefix('web/sticky-promotion')->group(function () {
    Route::get('/{torrent}', [Controller::class, 'show'])->whereNumber('torrent');
    Route::post('/{torrent}', [Controller::class, 'store'])->whereNumber('torrent')->middleware('throttle:20,1');
    Route::get('/{torrent}/status', [Controller::class, 'status'])->whereNumber('torrent');
});
