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
use App\Services\TokenService;
use App\Transactions;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ZIPITRECEIVE extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zipit_receive:run';

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


    public function handle (Request $request){
        $process_txn = BRJob::where('txn_status', 'PENDING')
            ->where('txn_type',ZIPIT_RECEIVE)
            ->sharedLock()
            ->get();


        if (!isset($process_txn)) {
            return response([
                'code' => '01',
                'description' => 'No transaction to process',
            ]);
        }



        foreach ($process_txn as $item) {
            $response = $this->process_zipit_job($item->amount, $item->source_account, $item->rrn);
            if($response["code"] != '00'){
                $item->txn_status = 'PENDING';
                $item->response =  $response["description"];
                $item->save();
                continue;
            }

            $item->br_reference = $response["description"];
            $item->txn_status = 'COMPLETED';
            $item->response =  $response["description"];
            $item->save();

        }

    }

    public function process_zipit_job ($amount,$account,$rrn){


        $branch_id = substr($account, 0, 3);
        $destination_account_credit = array(
            'serial_no'         => '472100',
            'our_branch_id'      => $branch_id,
            'account_id'        => $account,
            'trx_description_id' => '007',
            'TrxDescription'   => "ZIPIT RECEIVE",
            'TrxAmount'        => $amount);


        $zimswitch_debit = array(
            'serial_no'         => '472100',
            'our_branch_id'      =>$branch_id,
            'account_id'        => ZIMSWITCH,
            'trx_description_id' => '008',
            'TrxDescription'   => "ZIPIT RECEIVE (RRN:$rrn, Destination: $account)",
            'TrxAmount'        => - $amount);


        $client = new Client();
        $token = TokenService::getToken();
        try{
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                'headers' => ['Authorization' => $token, 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' =>  array(
                        $destination_account_credit,
                        $zimswitch_debit,

                    )
                ]

            ]);

            //$response =$result->getBody()->getContents();
            $response = json_decode($result->getBody()->getContents());
            if($response->code == '00'){
                return array(
                    'code' =>   $response->code,
                    'description' =>   $response->transaction_batch_id

                );
            }

            return array(
                'code' =>   $response->code,
                'description' =>   $response->description,

            );



        }catch (RequestException $e) {

            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();

                Log::debug('Txn: ZIPIT SEND' . "$account     " . $exception);
                return array(
                    'code' => '100',
                    'description' => $exception


                );


            } else {


                Log::debug('ZIPIT SEND:' .$account.'  '. $account.' '. $e->getMessage());
                return array(
                    'code' => '100',
                    'description' =>$e->getMessage()


                );

            }
        }


    }








}