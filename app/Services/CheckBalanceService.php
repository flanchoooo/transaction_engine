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
use Services\TokenService;
use GuzzleHttp\Exception\RequestException;


class CheckBalanceService
{
    public static function checkBalance($account_number)
    {

        try
        {
        //TOKEN GENERATION
        $user = new Client();
        $res = $user->post(env('BASE_URL') . '/api/authenticate', [
            'json' => [
                'username' => env('TOKEN_USERNAME'),
                'password' => env('TOKEN_PASSWORD'),
            ]
        ]);
        $tok = $res->getBody()->getContents();
        $bearer = json_decode($tok, true);
        $authentication = 'Bearer ' . $bearer['id_token'];

        $client = new Client();
        $result = $client->post(env('BASE_URL') . '/api/accounts/balance', [

            'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
            'json' => [
                'account_number' => $account_number,
            ]
        ]);

        $balance_response = json_decode($result->getBody()->getContents());

        // BALANCE ENQUIRY LOGIC
        if ($balance_response->available_balance > 0.15) {

            return array(

                'code' => '00',
                'available_balance' => $balance_response->available_balance,
                'ledger_balance' => $balance_response->available_balance,

            );


        } else {

            return array([

                'code' => '51',
                'available_balance' => $balance_response->available_balance,
                'available_balance' => $balance_response->available_balance,

            ]);

                }

        }catch (RequestException $e) {
        if ($e->hasResponse()) {
            $exception = (string)$e->getResponse()->getBody();
            $exception = json_decode($exception);

            return array('code'  => '01',
                'error' => $exception);

            //return new JsonResponse($exception, $e->getCode());
        } else {
            return array('code'  => '01',
                'error' => $e->getMessage());
            //return new JsonResponse($e->getMessage(), 503);
        }
    }


    }

}