<?php
/**
 * Created by PhpStorm.
 * User: deant
 * Date: 7/12/18
 * Time: 4:49 PM
 */

namespace App\Services;
use App\Wallet;
use App\WalletTransactions;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\DB;



class generateUniqueId
{


    public static function generateID($length,$formatted)
    {

        $nums = '0123456789';
        // First number shouldn't be zero
        $out = $nums[ mt_rand(1, strlen($nums) - 1) ];
        // Add random numbers to your string
        for ($p = 0; $p < $length - 1; $p++)
            $out .= $nums[ mt_rand(0, strlen($nums) - 1) ];
        // Format the output with commas if needed, otherwise plain output
        if ($formatted)
            return number_format($out);
        return $out;



    }



}

