<?php

namespace App\Services;

use GuzzleHttp;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Auth;


class SessionToken
{
    public static function token(){
        session_start();
        if(!isset($_SESSION['token'])){
            $token = TokenService::getToken();
           return $_SESSION['token'] = $token;
        }

        return $_SESSION['token'];
    }


}