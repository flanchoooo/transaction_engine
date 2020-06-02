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
            define('AES_256_CBC', 'aes-256-cbc');
            $encryption_key = env('APP_KEY');
            $iv = 'encryptionIntVec';
            $encrypted = $pin . ':' . base64_encode($iv);
            $parts = explode(':', $encrypted);
            $decrypted = openssl_decrypt($parts[0], AES_256_CBC, $encryption_key, 0, base64_decode($parts[1]));
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