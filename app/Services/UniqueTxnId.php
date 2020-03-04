<?php

namespace App\Services;

use App\BRJob;
use Carbon\Carbon;
use GuzzleHttp;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;


class UniqueTxnId
{
    public static function transaction_id(){

        $now = Carbon::now();
        return $reference = $now->format('mdHisu');
    }


}