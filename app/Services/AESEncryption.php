<?php

namespace App\Services;




use Mockery\Exception;

class AESEncryption
{

    public  static function encrypt($plaintext, $password) {
        $method = "AES-256-CBC";
        $key = hash('sha256', $password, false);
        $iv = 'PxN0p4cXdjz8adVhzoqPmwmSUBAM38ab';
        $ciphertext = openssl_encrypt($plaintext, $method, $key, OPENSSL_RAW_DATA, $iv);
        $hash = hash_hmac('sha256', $ciphertext . $iv, $key, false);
        return  $hash;
    }

    public static  function decrypt($pin) {
        try {
            $decrypted = AESCtrl::decrypt($pin,env('APP_KEY'),256);
            return array(
                'code'          => '00',
                'pin'           =>  $decrypted,
                'error_message' =>  'No error',
            );

        }catch(\Exception $exception){
            return array(
                'code'          => '01',
                'pin'           => false,
                'error_message'           => $exception->getMessage(),
            );
        }
    }


}