<?php
/**
 * Created by PhpStorm.
 * User: deant
 * Date: 7/12/18
 * Time: 4:49 PM
 */

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;


class TokenService
{
    public static function getToken()
    {

        $user = new Client();
        $res = $user->post(env('BASE_URL') . '/api/authenticate', [
            'json' => [
                'username' => env('TOKEN_USERNAME'),
                'password' => env('TOKEN_PASSWORD'),
            ]
        ]);

        $tok = $res->getBody()->getContents();
        $bearer = json_decode($tok, true);
        return $sec = 'Bearer ' . $bearer['id_token'];




    }
}