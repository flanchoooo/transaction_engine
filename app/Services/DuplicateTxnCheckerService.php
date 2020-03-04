<?php

namespace App\Services;

use App\BRJob;
use App\T_Transactions;
use GuzzleHttp;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;


class DuplicateTxnCheckerService
{

    public static function check($serial_no)
    {
        $duplicated = T_Transactions::where('TraceNo', $serial_no)->get()->count();
        if($duplicated > 0){
            return array(
                'code'           => '01',
                'description '   => 'Duplicate transaction'
            );

        }else{
            return array(
                'code'           => '00',
                'description '   => 'Success'
            );
        }

    }


}