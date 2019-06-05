<?php

namespace App\Jobs;
use App\License;
use GuzzleHttp\Client;

class NotifyBills extends Job
{
    protected $source;
    protected $destination;
    protected $amount_sent;
    protected $source_balance;
    protected $destination_balance;
    protected $reference;
    protected $product_name;




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
    public function __construct($source,$destination,$amount_sent,$source_balance,$destination_balance,$reference,$product_name){
        //
        $this->source = $source;
        $this->destination = $destination;
        $this->amount_sent = $amount_sent;
        $this->source_balance = $source_balance;
        $this->destination_balance = $destination_balance;
        $this->reference = $reference;
        $this->product_name = $product_name;

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
            'text' => "$this->product_name bill payment of $license->currency $this->amount_sent was successful. New Balance $license->currency $this->source_balance. Ref:$this->reference"

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


        $result->getBody()->getContents();



    }


}
