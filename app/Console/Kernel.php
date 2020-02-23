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
        \App\Console\Commands\FailedPurchase::class,
        \App\Console\Commands\WalletSettlement::class,
        \App\Console\Commands\FailedBalance::class
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('penalty_deduction:run')->cron('0 0 * * *')
            ->withoutOverlapping();

        $schedule->command('failed_purchase:run')->cron('* * * * *')
            ->withoutOverlapping();

        $schedule->command('failed_balance:run')->cron(' 5 * * * *')
            ->withoutOverlapping();

        $schedule->command('failed_zipit:run')->cron('* * * * *')
            ->withoutOverlapping();

        $schedule->command('purchase:run')->cron('2 * * * *')
            ->withoutOverlapping();

        $schedule->command('cash:run')->cron('3 * * * *')
            ->withoutOverlapping();

        $schedule->command('reversal:run')->cron('2 * * * *')
            ->withoutOverlapping();

        $schedule->command('wallet_settlement:run')->cron('* * * * *')
            ->withoutOverlapping();


    }



}
