<?php
/**
 * Created by PhpStorm.
 * User: deant
 * Date: 11/2/18
 * Time: 3:57 PM
 */

namespace App\Services;

use GuzzleHttp;
use GuzzleHttp\Client;
use http\Env\Request;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;


class CheckBalanceService
{
    public static function checkBalance($account_number)
    {

        try
        {


        $authentication  = TokenService::getToken();

        $client = new Client();
        $result = $client->post(env('BASE_URL') . '/api/accounts/balance', [

            'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
            'json' => [
                'account_number' => $account_number,
            ]
        ]);

        $balance_response = json_decode($result->getBody()->getContents());

        return array(

                'code'              => '00',
                'available_balance' => $balance_response->available_balance,
                'ledger_balance'    => $balance_response->available_balance,

        );



        }catch (RequestException $e) {

            if ($e->hasResponse()) {
           $exception = (string)$e->getResponse()->getBody();


                Log::debug('Account Number:'.$account_number.' '.$exception);

                return array(
                    'code'          => '01',
                    'description'   => 'BR could not process your request.');

        }

        else {


            Log::debug('Account Number:'.$account_number.' '.$e->getMessage());

            return array(
                'code'          => '01',
                'description'   => 'BR could not process your request.');

        }
    }


    }

}