<?php
/**
 * Created by PhpStorm.
 * User: deant
 * Date: 7/12/18
 * Time: 4:49 PM
 */

namespace App\Services;




use App\User;
use Illuminate\Support\Facades\Log;


class LoggingService
{
    public static function message($message){
        try {
            Log::debug($message);
        }catch (\Exception $exception){

        }
    }

}