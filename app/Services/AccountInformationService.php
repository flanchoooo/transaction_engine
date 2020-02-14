<?php

namespace App\Services;

use GuzzleHttp;
use GuzzleHttp\Exception\RequestException;


class AccountInformationService
{
    public static function getUserDetails($account_id){
        $token = TokenService::getToken();
        $headers = array(
            'Accept'        => 'application/json',
            'Authorization' => $token,
        );
        $client = new GuzzleHttp\Client(['headers' => $headers]);
        try {
            $res = $client->post(env('BASE_URL') . '/api/customers', ['json' => [
                'account_number' => $account_id,
            ]]);


         // return  $resultz =  $res->getBody()->getContents();
            $resultz =  json_decode($res->getBody()->getContents());
            return array(
                'code' => '00',
                'status' => $resultz->ds_account_customer_info->acstatus,
                'token'  => $token
            );
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                $exception = json_decode($exception);

                return array('code'  => '01',
                             'error' => 'Failed to process');

                //return new JsonResponse($exception, $e->getCode());
            } else {
                return array('code'  => '01',
                    'error' => 'Failed to process');
                //return new JsonResponse($e->getMessage(), 503);
            }
        }

    }


}