<?php

namespace App\Services;




use App\ATMOTP;
use App\OTP;
use Mockery\Exception;

class OTPService
{
    public static function generateOtp($mobile,$type){
        $otp = strtoupper(bin2hex(openssl_random_pseudo_bytes(3)));
        $message = "Your validation OTP is $otp";
        OTP::updateOrCreate(
            ['mobile' => $mobile],
            ['otp'  => "123456", 'type' => $type]

        );

        //TODO
        //Send Message
    }

    public static function generateATMWithdrawlOtp(){
        $otp = strtoupper(bin2hex(openssl_random_pseudo_bytes(3)));
        $auth = strtoupper(bin2hex(openssl_random_pseudo_bytes(3)));
        return array(
          'initiation_code'     => $otp,
          'authorization_code'  => $auth
        );

        //TODO
        //Send Message
    }

}