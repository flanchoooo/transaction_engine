<?php
/**
 * Created by PhpStorm.
 * User: deant
 * Date: 7/12/18
 * Time: 4:49 PM
 */

namespace App\Services;



use App\Devices;
use App\LuhnCards;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use function GuzzleHttp\Promise\all;
use http\Env\Request;

class TsambaService
{
    public static function tumiraTsamba($sender_mobile,
                                        $sender_message,
                                        $from,
                                        $sms_number,
                                        $receipient_mobile,
                                        $receipient_message)
    {
        if($sms_number == '2'){

            try {
                $client = new Client();
                $sender_data = array(
                    'from' => $from,
                    'to' => $sender_mobile,
                    'text' => $sender_message
                );

                $headers = array(
                    'Accept' => 'application/json',
                    'Authorization' => 'Basic ' . env('GIKKO_TOKEN'),
                );

                $result = $client->post(env('GIKKO_URL'), [
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
                'from' => $from,
                'to'   => $receipient_mobile,
                'text' =>  $receipient_message
            );

            $res = $clients->post(env('GIKKO_URL'), [
                'headers' => $headers,
                'json' => $receipient_data,
            ]);

            $res->getBody()->getContents();

        }catch (ClientException $exception){
                return array('code'=> '01', 'description' => 'Failed to send SMS');
            }
        }




    }

}