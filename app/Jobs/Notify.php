<?php

namespace App\Jobs;
use App\License;
use GuzzleHttp\Client;

class Notify extends Job
{
    protected $source;
    protected $destination;
    protected $amount_sent;
    protected $source_balance;
    protected $destination_balance;
    protected $reference;




    /**
     * Create a new job instance.
     *
     * @param $transaction_type
     * @param $status
     * @param $account
     * @param $card_number
     * @param $debit
     * @param $credit
     * @param $reference
     * @param $fee
     * @param $merchant
     */
    public function __construct($source,$destination,$amount_sent,$source_balance,$destination_balance,$reference){
        //
        $this->source = $source;
        $this->destination = $destination;
        $this->amount_sent = $amount_sent;
        $this->source_balance = $source_balance;
        $this->destination_balance = $destination_balance;
        $this->reference = $reference;

    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(){


        $license = License::find(1);

        $sender_data = array(

            'from' => 'eBucks',
            'to'   =>  $this->source,
            'text' => "Transfer to mobile $this->destination of $this->amount_sent was successful. New Balance $license->currency $this->source_balance. Ref:$this->reference"

        );

        $r_data = array(

            'from' => 'eBucks',
            'to'   =>  $this->destination,
            'text' => "$license->currency $this->amount_sent has been credited into your account from mobile $this->source. New Balance $license->currency  $this->destination_balance. Ref:$this->reference",
        );

        $headers = array(
            'Accept'        => 'application/json',
            'Authorization' => 'Basic ' . env('GIKKO_TOKEN'),
        );


        $client = new Client();
        $result = $client->post(env('GIKKO_URL'), [

            'headers' => $headers,
            'json' => $sender_data,

        ]);


        $client_ = new Client();
        $result_ = $client_->post(env('GIKKO_URL'), [

            'headers' => $headers,
            'json' => $r_data,

        ]);


        $result->getBody()->getContents();
        $result_->getBody()->getContents();


    }


}
