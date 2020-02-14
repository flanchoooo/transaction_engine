<?php
/**
 * Created by PhpStorm.
 * User: deant
 * Date: 7/12/18
 * Time: 4:49 PM
 */

namespace App\Services;



use App\LuhnCards;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use function GuzzleHttp\Promise\all;
use http\Env\Request;

class SmsNotificationService
{
    public static function send($sms_number,$sender_mobile,$sender_message,$r_mobile,$r_message,
                                $single_sender_mobile,$single_sender_message)
    {


        if($sms_number == '2'){

            try {

                $client = new Client();
                $sender_data = array(
                    'sender'           => env('SMS_SENDER'),
                    'recipient'        => $sender_mobile,
                    'message'          => $sender_message,
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

            }


            try {


                //Second SMS
                $clients = new Client();
                $receipient_data = array(
                    'sender'        => env('SMS_SENDER'),
                    'recipient'     => $r_mobile,
                    'message'       =>  $r_message
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

            }
        }

        if($sms_number == '1'){
            try {

                $client = new Client();
                $sender_data = array(
                    'sender'        => env('SMS_SENDER'),
                    'recipient'         => $single_sender_mobile,
                    'message'           => $single_sender_message
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

            }

        }


    }

}