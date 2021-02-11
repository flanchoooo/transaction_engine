<?php
/**
 * Created by PhpStorm.
 * User: flavi
 * Date: 29/4/2019
 * Time: 4:22 PM
 */

namespace App\Http\Controllers;


class WalletTransaction
{

    public static function generateMobileOtp($user_id, $type){
        $otp = strtoupper(bin2hex(openssl_random_pseudo_bytes(3)));

    }

}