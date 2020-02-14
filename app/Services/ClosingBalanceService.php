<?php
/**
 * Created by PhpStorm.
 * User: deant
 * Date: 7/12/18
 * Time: 4:49 PM
 */

namespace App\Services;




use App\User;



class ClosingBalanceService
{
    public static function getClosingBalance($array_content)
    {
        foreach($array_content as $array){
            return $closing =  $array["balance_after_txn"];
        }


    }

}