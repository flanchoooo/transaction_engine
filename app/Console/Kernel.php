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
        \App\Console\Commands\Cash::class
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {

        $schedule->command('balance_enquiry:run')->everyMinute();
        $schedule->command('purchase:run')->everyMinute();
        $schedule->command('cash:run')->everyMinute();


    }



}
