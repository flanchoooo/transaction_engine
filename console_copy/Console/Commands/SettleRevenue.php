<?php

namespace App\Console\Commands;
use App\Services\TokenServiceZM;
use App\Wallet;
use App\WalletTransactions;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SettleRevenue extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'settle:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process Revenue settling job';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {






      return \App\Services\SettleRevenue::settle();



    }








}