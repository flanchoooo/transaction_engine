<?php
/**
 * Created by PhpStorm.
 * User: deant
 * Date: 11/2/18
 * Time: 3:57 PM
 */

namespace App\Services;

use App\Transactions;
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



        $client = new Client();
        $result = $client->post(env('BASE_URL') . '/api/accounts/balance', [

            'headers' => ['Authorization' => 'Balance', 'Content-type' => 'application/json',],
            'json' => [
                'account_number' => $account_number,
            ]
        ]);


       //return $balance_response = $result->getBody()->getContents();
        $balance_response = json_decode($result->getBody()->getContents());

            if($balance_response->code != '00'){
                return array(
                    'code'              => '100',
                    'description'       => 'Invalid BR account',
                );

            }
        return array(
                'code'              => '000',
                'available_balance' => $balance_response->available_balance,
        );



        }catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                return array(
                    'code'              => '100',
                    'description'       => '1. Failed to reach CBS',
                );
            } else {
                return array(
                    'code'              => '100',
                    'description'       => '2. Failed to reach CBS',
                );

            }

        }


    }

}