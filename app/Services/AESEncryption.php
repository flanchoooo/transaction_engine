<?php

namespace App\Services;




use Mockery\Exception;

class AESEncryption
{

    public  static function encrypt($plaintext, $password) {
        $method = "AES-256-CBC";
        $key = hash('sha256', $password, false);
        $iv = openssl_random_pseudo_bytes(16);
        $ciphertext = openssl_encrypt($plaintext, $method, $key, OPENSSL_RAW_DATA, $iv);
        $hash = hash_hmac('sha256', $ciphertext . $iv, $key, false);
        return $iv . $hash . $ciphertext;
    }

    public static  function decrypt($pin) {
        try {
            $encryption_key = "aesEncryptionKey";
            $iv = 'encryptionIntVec';
            $encrypted = $pin . ':' . base64_encode($iv);
            $parts = explode(':', $encrypted);
            $decrypted = openssl_decrypt($parts[0], 'aes-128-cbc', $encryption_key, 0, base64_decode($parts[1]));
            return array(
                'code'          => '00',
                'pin'           =>  $decrypted
            );

        }catch(Exception $exception){
            return array(
                'code'          => '01',
                'pin'           => false
            );
        }
    }


}