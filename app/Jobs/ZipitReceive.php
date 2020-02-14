<?php

namespace App\Jobs;
use App\BRJob;
use App\License;
use App\Services\FeesCalculatorService;
use App\Services\LoggingService;
use App\Services\TokenService;
use ErrorException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use mysql_xdevapi\Exception;

class ZipitReceive extends Job
{



    protected $account_number;
    protected $amount;
    protected $reference;
    protected $rrn;
    protected $narration;




    public function __construct($account_number,$amount,$reference,$rrn,$narration){
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


        $branch_id = substr($this->account_number, 0, 3);
        $account_debit = array(
            'serial_no'             => '472100',
            'our_branch_id'         => $branch_id,
            'account_id'            => $this->account_number,
            'trx_description_id'    => '007',
            'trx_description'       => "Zipit receive | $this->narration",
            'trx_amount'            => $this->amount);


        $account_credit = array(
            'serial_no'             => '472100',
            'our_branch_id'         =>$branch_id,
            'account_id'            => ZIMSWITCH,
            'trx_description_id'    => '008',
            'trx_description'       => "Zipit receive: $this->rrn account:$this->account_number",
            'trx_amount'            =>   - $this->amount);


        try {

            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/internal-transfer', [
                'headers' => ['Authorization' => 'Zipit receive', 'Content-type' => 'application/json',],
                'json' => [
                    'bulk_trx_postings' => array(
                        $account_debit,
                        $account_credit,
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
                LoggingService::message('Zipit receive transaction failed  $response->description'.$this->account_number);
                return "failed";
            }

            BRJob::where('tms_batch', $this->reference)->update([
                'txn_status'    => 'COMPLETED',
                'version'    => 0,
                'br_reference'  =>  $response->transaction_batch_id,
            ]);
            LoggingService::message('Zipit receive transaction processed successfully'.$this->account_number);



        }catch (\Exception $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                BRJob::where('tms_batch', $this->reference)->update([
                    'txn_status'    => 'FAILED',
                    'version'           => 0,
                    'response'          =>  '01:Error reaching CBS , process zipit receive txn',
                ]);
                Log::debug("01:Error reaching CBS,zipit receive :$this->account_number :  $exception");

            } else {
                BRJob::where('tms_batch', $this->reference)->update([
                    'txn_status'    => 'FAILED',
                    'version'           => 0,
                    'response'          =>  '01:Error reaching CBS , process zipit receive txn',
                ]);
                LoggingService::message("01:Error reaching CBS, zipit receive". $e->getMessage());

            }
        }
    }


}
