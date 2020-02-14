<?php

namespace App\Jobs;
use App\License;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class NotifyBills extends Job
{



    protected $sender_mobile;
    protected $sender_message;
    protected $from;
    protected $r_mobile;
    protected $r_message;
    protected $sms_number;




    public function __construct($sender_mobile,$sender_message,$from,$r_mobile,$r_message,$sms_number){
        //
        $this->sender_mobile = $sender_mobile;
        $this->sender_message = $sender_message;
        $this->from = $from;
        $this->r_mobile = $r_mobile;
        $this->r_message = $r_message;
        $this->sms_number = $sms_number;

    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(){

        if($this->sms_number == '2'){

            try {

                $client = new Client();
                $sender_data = array(
                    'sender'            => $this->from,
                    'recipient'        => $this->sender_mobile,
                    'message'           => $this->sender_message
                );

                $headers = array(
                    'Accept' => 'application/json',
                    'application_uid' =>  env('SMS_UUID'),
                );

                $result = $client->post(env('SMS_NOTIFY_URL'), [
                    'headers' => $headers,
                    'json' => $sender_data,
                ]);

                $result->getBody()->getContents();

            }catch (ClientException $exception){
                return array('code'=> '01', 'description' => 'Failed to send SMS');
            }


            try {


                //Second SMS
                $clients = new Client();
                $receipient_data = array(
                    'sender'        => $this->from,
                    'recipient'     => $this->r_mobile,
                    'message'       =>  $this->r_message
                );

                $headers = array(
                    'Accept' => 'application/json',
                    'application_uid' =>  env('SMS_UUID'),
                );

                $res = $clients->post(env('SMS_NOTIFY_URL'), [
                    'headers' => $headers,
                    'json' => $receipient_data,
                ]);

                $res->getBody()->getContents();

            }catch (ClientException $exception){
                return array('code'=> '01', 'description' => 'Failed to send SMS');
            }
        }


        if($this->sms_number == '1'){
            try {

                $client = new Client();
                $sender_data = array(
                    'sender'            => $this->from,
                    'recipient'         => $this->sender_mobile,
                    'message'           => $this->sender_message
                );


                $headers = array(
                    'Accept' => 'application/json',
                    'application_uid' =>  env('SMS_UUID'),
                );

                $result = $client->post(env('SMS_NOTIFY_URL'), [
                    'headers' => $headers,
                    'json' => $sender_data,
                ]);

                $result->getBody()->getContents();

            }catch (ClientException $exception){
                return array('code'=> '01', 'description' => 'Failed to send SMS');
            }

        }



    }


}
