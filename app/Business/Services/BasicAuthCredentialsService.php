<?php
/**
 * Created by PhpStorm.
 * User: namar
 * Date: 25-Oct-18
 * Time: 10:20 AM
 */

namespace App\Business\Services;


use Illuminate\Contracts\Encryption\DecryptException;

class BasicAuthCredentialsService
{
    public function retrieveCredentialsFromCache(){

        if (session()->get("token") !== null) {

            return "Bearer " . session()->get("token");

        } else {
            return [];
        }
    }
}
