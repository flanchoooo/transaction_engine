<?php
/**
 * Created by PhpStorm.
 * User: deant
 * Date: 7/12/18
 * Time: 4:49 PM
 */

namespace App\Services;



use App\Devices;
use App\LuhnCards;
use Carbon\Carbon;
use function GuzzleHttp\Promise\all;
use http\Env\Request;

class DeviceService
{
    public static function checkDevice($imei)
    {


        $device_existence = Devices::where('imei', $imei);

        if ($device_existence === 0){

            return response(['code' => '58', 'Description' => 'Devices does not exists']);

        }


        // check if the card exists
         $card_existance = LuhnCards::where('track_2', $card_number)->count();

         if ($card_existance === 0){

             return 14;

         }


         //Check expiry
        $results = LuhnCards::all()->where('track_2', $card_number);

        foreach ($results as $result){

         $account_result = $result->account_number;

          //Expiry
          $date = new Carbon("$result->expiry_year-$result->expiry_month-01");
          $now_date =  Carbon::now();

            if ($now_date >= $date) {

                return 33;

            }

            if(!isset($account_result)){

                return 05;

            }

            //Check State
            if($result->state != 1){

                return $result->state;


            }else{

                return  $result->account_number;



            }



        }


    }

}