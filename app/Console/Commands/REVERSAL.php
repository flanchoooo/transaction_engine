<?php

namespace App\Console\Commands;

use App\Accounts;
use App\BRAccountID;
use App\BRJob;
use App\Deduct;
use App\Devices;
use App\Jobs\NotifyBills;
use App\LuhnCards;
use App\MDR;
use App\Merchant;
use App\MerchantAccount;
use App\PenaltyDeduction;
use App\PendingTxn;
use App\Services\AccountInformationService;
use App\Services\FeesCalculatorService;
use App\Services\LoggingService;
use App\Services\TokenService;
use App\Transactions;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class REVERSAL extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reversal:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process Pending Transactions';

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


    public function handle (){

        $process_txn = BRJob::where('reversed','false')
            ->sharedLock()
            ->get();

        if ($process_txn->isEmpty()) {
            return response([
                'code' => '01',
                'description' => 'No transaction to process',
            ]);
        }

        foreach ($process_txn as $item) {
            $response = $this->process($item->br_reference,$item->source_account);
            LoggingService::message("Transaction successfully reversed");
            if($response["description"] == 'API : BREXMSG[Cannot find table 0.]'){
                $item->txn_status = 'COMPLETED';
                $item->reversed = 'true';
                $item->response =  $response["description"];
                $item->save();
                continue;
            }

            if($response["description"] == 'Transaction already reversed'){
                $item->reversed = 'true';
                $item->response =  $response["description"];
                $item->save();
                continue;
            }

        }
    }

    public function process ($br_reference,$account){

        if(isset($account)){
            $branch_id = substr($account, 0, 3);
        }
        else{
            $branch_id = '001';
        }
        try{

            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/reversals', [

                'headers' => ['Authorization' => 'Reversal', 'Content-type' => 'application/json',],
                'json' => [
                    'branch_id' => $branch_id,
                    'transaction_batch_id' => $br_reference
                ]
            ]);

            $response = json_decode($result->getBody()->getContents());
            return array(
                'code' => $response->code,
                'description' => $response->description,

            );

        }catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                Log::debug('Txn REVERSAL' . "$account     " . $exception);
                return array(
                    'code' => '100',
                    'description' => $exception
                );
            }else {
                Log::debug('REVERSAL:' .$account.'  '. $account.' '. $e->getMessage());
                return array(
                    'code' => '100',
                    'description' =>$e->getMessage()
                );
            }
        }


    }









}