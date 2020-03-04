<?php

namespace App\Console\Commands;

use App\Accounts;
use App\Deduct;
use App\Devices;
use App\Employee;
use App\Jobs\NotifyBills;
use App\Jobs\SaveTransaction;
use App\MDR;
use App\Merchant;
use App\MerchantAccount;
use App\PendingTxn;
use App\Services\FeesCalculatorService;
use App\Services\LoggingService;
use App\Services\TokenService;
use App\Transaction;
use App\Transactions;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;

class Purchase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'purchase:run';

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
    public function handle(){

        $items = PendingTxn::where('transaction_type_id', PURCHASE_BANK_X)
            ->where('status', 'DRAFT')
            ->get();


        if ($items->isEmpty()) {
            return response([
                'code' => '01',
                'description' => 'No transaction to process',
            ]);
        }


        foreach ($items as $item){
            $merchant_id = Devices::where('imei', $item->imei)->first();
            $response = $this->purchase_deduction($item->transaction_id,$item->card_number,$merchant_id->merchant_id,$item->amount,$item->imei);
            if($response["code"] == '00'){
                LoggingService::message('Merchant settled successfully');
                $item->status= 'COMPLETED';
                $item->save();
                echo $merchant_id->merchant_id.' '. 'Merchant settled successfully';

            }
        }

    }

    public function purchase_deduction($id,$card,$merchant_id,$amount,$imei)
    {

        $card_number = str_limit($card, 16, '');
        $merchant_account = MerchantAccount::where('merchant_id',$merchant_id)->first();
        $branch_id = substr($merchant_account->account_number, 0, 3);



        $fees_result = FeesCalculatorService::calculateFees(
            $amount,
            '0.00',
            PURCHASE_BANK_X,
            $merchant_id,$merchant_account->account_number
        );



        $debit_zimswitch_with_purchase_amnt = array(
            'serial_no'             => '472100',
            'our_branch_id'         => $branch_id,
            'account_id'            => ZIMSWITCH,
            'trx_description_id'    => '007',
            'trx_description'       => 'POS SALE RRN:'.$id,
            'trx_amount'            => '-' . $amount);


        $credit_merchant_account = array(
            'serial_no'             => '472100',
            'our_branch_id'         => substr($merchant_account->account_number, 0, 3),
            'account_id'             => $merchant_account->account_number,
            'trx_description_id'    => '008',
            'trx_description'       => 'POS SALE RRN: '.$id,
            'trx_amount'            => $amount);


        $debit_zimswitch = array(
            'serial_no'             => '472100',
            'our_branch_id'         => $branch_id,
            'account_id'            => ZIMSWITCH,
            'trx_description_id'    => '007',
            'trx_description'       => 'POS SALE Acquirer Fee:'.$id,
            'trx_amount'            => '-' . $fees_result['acquirer_fee']);


        $credit_revenue = array(
            'serial_no'             => '472100',
            'our_branch_id'         => substr($merchant_account->account_number, 0, 3),
            'account_id'             => REVENUE,
            'trx_description_id'    => '008',
            'trx_description'       => 'POS SALE Acquirer Fee:'.$id,
            'trx_amount'            => $fees_result['acquirer_fee']);



        $client = new Client();


        try {
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [

                'headers' => ['Authorization' => 'Auth', 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(
                        $debit_zimswitch_with_purchase_amnt,
                        $credit_merchant_account,
                        $debit_zimswitch,
                        $credit_revenue
                    ),
                ]
            ]);


            $response = json_decode($result->getBody()->getContents());
            if($response->code == '00'){
                $rev =  $fees_result['mdr'] + $fees_result['acquirer_fee'];
                $zimswitch_amount = $amount + $fees_result['acquirer_fee'];
                $merchant_account_amount = $amount  - $fees_result['mdr'];

                Transactions::create([

                    'txn_type_id'         => PURCHASE_BANK_X,
                    'revenue_fees'        => $rev,
                    'interchange_fees'    => '0.00',
                    'zimswitch_fee'       => '-'.$zimswitch_amount,
                    'transaction_amount'  => $amount,
                    'total_debited'       => $zimswitch_amount,
                    'total_credited'      => $zimswitch_amount,
                    'batch_id'            => $response->transaction_batch_id,
                    'switch_reference'    => $id,
                    'merchant_id'         => $merchant_id,
                    'transaction_status'  => 1,
                    'account_debited'     => ZIMSWITCH,
                    'pan'                 => $card_number,
                    'merchant_account'    => $merchant_account_amount,
                    'description'         => 'Transaction successfully processed.',

                ]);

                MDR::create([
                    'amount'            => $fees_result['mdr'],
                    'imei'              => $imei,
                    'merchant'          => $merchant_id,
                    'source_account'    => $merchant_account->account_number,
                    'txn_status'        => 'PENDING',
                    'batch_id'          => $response->transaction_batch_id,

                ]);

                return array(
                    'code' => $response->code
                );

            }


        } catch (ClientException $exception) {

            return array(
                'code' => '01'
            );

        }




    }

}
