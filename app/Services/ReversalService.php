<?php

namespace App\Services;

use App\BRJob;

use GuzzleHttp;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;


class ReversalService
{

    public static function sendReversalRequest ($br_reference,$account){

        if(isset($account)){
            $branch_id = substr($account, 0, 3);
        }
        else{
            $branch_id = '001';
        }
        try{

            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/reversals', [
                'headers' => ['Authorization' => 'Reversal', 'Content-type' => 'application/json',],
                'json' => [
                    'branch_id'              => $branch_id,
                    'transaction_batch_id'   => $br_reference
                ]
            ]);

            $response = json_decode($result->getBody()->getContents());
            return array(
                'code'          => $response->code,
                'description'   => $response->description,

            );

        }catch (RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                return array(
                    'code' => '100',
                    'description' => $exception
                );
            }else {
                return array(
                    'code' => '100',
                    'description' =>$e->getMessage()
                );
            }
        }


    }


}