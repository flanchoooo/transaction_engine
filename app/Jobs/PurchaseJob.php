<?php

namespace App\Jobs;
use App\BRJob;
use App\License;
use App\Services\FeesCalculatorService;
use App\Services\LoggingService;
use App\Services\TokenService;
use App\Wallet;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use mysql_xdevapi\Exception;

class PurchaseJob extends Job
{



    protected $account_number;
    protected $amount;
    protected $reference;
    protected $rrn;
    protected $narration;





    public function __construct($account_number,$amount,$reference,$rrn, $narration){
        //
        $this->account_number = $account_number;
        $this->amount = $amount;
        $this->reference = $reference;
        $this->rrn = $rrn;
        $this->narration = $narration;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(){

        $fees_charged = FeesCalculatorService::calculateFees($this->amount, '0.00', PURCHASE_OFF_US, HQMERCHANT,$this->account_number);
        $fees_total = $fees_charged['fees_charged'] - $fees_charged['tax'];
        $branch_id = substr($this->account_number, 0, 3);
        $debit_client_purchase_amount = array(
            'serial_no'                 => '472100',
            'our_branch_id'             => $branch_id,
            'account_id'                => $this->account_number,
            'trx_description_id'        => '007',
            'trx_description'           => "POS Purchase | $this->narration | $this->account_number | $this->rrn",
            'trx_amount'                => '-' . $this->amount);

        $tax = array(
            'serial_no'                 => '472100',
            'our_branch_id'             => $branch_id,
            'account_id'                => $this->account_number,
            'trx_description_id'        => '007',
            'trx_description'           =>  "Transaction tax | $this->narration | $this->account_number | $this->rrn",
            'trx_amount'                => '-' . $fees_charged['tax']);


        $debit_client_fees = array(
            'serial_no'                 => '472100',
            'our_branch_id'             => $branch_id,
            'account_id'                => $this->account_number,
            'trx_description_id'        => '007',
            'trx_description'           =>  "Fees | $this->narration | $this->account_number | $this->rrn",
            'trx_amount'                => '-' . $fees_total);

        $credit_tax = array(
            'serial_no'                 => '472100',
            'our_branch_id'             => $branch_id,
            'account_id'                => TAX,
            'trx_description_id'        => '008',
            'trx_description'           =>  "Transaction tax | $this->narration | $this->account_number | $this->rrn",
            'trx_amount'                => $fees_charged['tax']);

        $credit_zimswitch_amount = array(
            'serial_no'                 => '472100',
            'our_branch_id'             => $branch_id,
            'account_id'                => ZIMSWITCH,
            'trx_description_id'        => '008',
            'trx_description'           => "POS Purchase | $this->narration | $this->account_number | $this->rrn",
            'trx_amount'                =>  $this->amount);


        $acquirer_fee = array(
            'serial_no'                 => '472100',
            'our_branch_id'             => $branch_id,
            'account_id'                => REVENUE,
            'trx_description_id'        => '008',
            'trx_description'           => "Acquirer fee | $this->narration | $this->account_number | $this->rrn",
            'trx_amount'                =>  $fees_charged['acquirer_fee']);

        $zimswitch_fee = array(
            'serial_no'                 => '472100',
            'our_branch_id'             => $branch_id,
            'account_id'                => ZIMSWITCH,
            'trx_description_id'        => '008',
            'trx_description'           => "Z06 - Switch fee | $this->narration | $this->account_number | $this->rrn",
            'trx_amount'                =>  $fees_charged['zimswitch_fee']);

        try {

            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [
                'headers' => ['Authorization' => 'Purchase', 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(
                        $debit_client_purchase_amount,
                        $debit_client_fees,
                        $credit_tax,
                        $acquirer_fee,
                        $zimswitch_fee,
                        $credit_zimswitch_amount,
                        $tax
                    ),
                ]
            ]);

            $response = json_decode($result->getBody()->getContents());
            if ($response->code != '00'){
                BRJob::where('tms_batch', $this->reference)->update([
                    'txn_status'    => 'FAILED',
                    'version'       => 0,
                    'response'      =>  $response->description,
                ]);

                LoggingService::message("Purchase transaction failed: $response->description :$this->account_number");
                return 'FAILED';
            }

            BRJob::where('tms_batch', $this->reference)->update([
                'txn_status'    => 'COMPLETED',
                'version'    => 0,
                'br_reference'  =>  $response->transaction_batch_id,
            ]);

            LoggingService::message("Purchase transaction processed successfully for account:$this->account_number");


        }catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                BRJob::where('tms_batch', $this->reference)->update([
                    'txn_status'        => 'FAILED',
                    'version'           => 0,
                    'response'          =>  '01:Error reaching CBS , process purchase txn',
                ]);
                LoggingService::message("01:Error reaching CBS , process purchase txn $this->account_number: $exception");
            } else {
                BRJob::where('tms_batch', $this->reference)->update([
                    'txn_status'        => 'FAILED',
                    'version'           => 0,
                    'response'          =>  '01:Error reaching CBS process purchase txn:',
                ]);
                LoggingService::message("01:Error reaching CBS process purchase txn: $this->account_number". $e->getMessage());
                Log::debug("01:Error reaching CBS process purchase txn: $this->account_number". $e->getMessage());
            }
        }
    }




}
