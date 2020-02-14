<?php
/**
 * Created by PhpStorm.
 * User: deant
 * Date: 7/12/18
 * Time: 4:49 PM
 */

namespace App\Services;
use GuzzleHttp;

use Illuminate\Http\JsonResponse;


class TokenServiceZM
{
    public static function getToken()
    {

        $headers = array(
            'Accept' => 'application/json',
            'Authorization' => env('TOKEN_LOGIN_TOKEN'),
        );

        $client = new GuzzleHttp\Client(['headers' => $headers]);
        try {
            $res = $client->post(env('AUTH_SERVER_BASE_URL') . '/login/', ['json' => [
                'applicationUID' => env('TOKEN_UID'),
                'username' => env('TOKEN_USERNAME'),
                'password' => env('TOKEN_PASSWORD')

            ]]);
            return $res->getBody()->getContents();
        } catch (GuzzleHttp\Exception\RequestException $e) {
            if ($e->hasResponse()) {
                $exception = (string)$e->getResponse()->getBody();
                $exception = json_decode($exception);
                return new JsonResponse($exception, $e->getCode());
            } else {
                return new JsonResponse($e->getMessage(), 503);
            }

        }


    }
}