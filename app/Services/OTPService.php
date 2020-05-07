<?php

namespace App\Services;




use App\OTP;
use Mockery\Exception;

class OTPService
{
    public static function generateOtp($mobile){
        $otp = strtoupper(bin2hex(openssl_random_pseudo_bytes(3)));
        $message = "Your validation OTP is $otp";
        OTP::updateOrCreate(
            ['mobile' => $mobile],
            ['otp'  => "123456", 'type' => 'WALLET']

        );

        //TODO
        //Send Message
    }

}