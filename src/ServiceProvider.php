<?php

namespace NexusPlugin\StickyPromotion;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Nexus\Database\NexusDB;

class ServiceProvider extends BaseServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(dirname(__DIR__) . '/resources/views', Repository::ID);
        $this->loadRoutesFrom(dirname(__DIR__) . '/routes/web.php');
        $this->loadMigrationsFrom(dirname(__DIR__) . '/database/migrations');
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->call(function () {
                if (NexusDB::hasTable('plugin_sticky_promotions')) { (new Service())->settle(); }
            })->name('sticky-promotion:settle')->everyMinute()->withoutOverlapping();
        });
    }
}
