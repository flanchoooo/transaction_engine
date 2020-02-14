<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Laravel\Lumen\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        \App\Console\Commands\Txns::class,
        \App\Console\Commands\Purchase::class,
        \App\Console\Commands\Cash::class,
        \App\Console\Commands\SettleRevenue::class,
        \App\Console\Commands\MdrDeduction::class,
        \App\Console\Commands\PenaltyDeduction::class,
        \App\Console\Commands\REVERSAL::class,
        \App\Console\Commands\FAILED::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('penalty_deduction:run')->cron('* * * * *')
            ->withoutOverlapping();

        $schedule->command('failed:run')->cron('* * * * *')
            ->withoutOverlapping();

        $schedule->command('purchase:run')->cron('* * * * *')
            ->withoutOverlapping();

        $schedule->command('cash:run')->cron('* * * * *')
            ->withoutOverlapping();

        $schedule->command('reversal:run')->cron('* * * * *')
            ->delay(1)
            ->withoutOverlapping();


    }



}
