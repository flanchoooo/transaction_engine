<?php
/**
 * Created by PhpStorm.
 * User: deant
 * Date: 7/12/18
 * Time: 4:49 PM
 */

namespace App\Services;




use App\User;



class ApiTokenValidity
{
    public static function tokenValidity($token)
    {


        return $count = User::where('api_token', $token)->get();

         if (isset($count)){

             return "TRUE";

         }else{

             return "FALSE";

         }

    }

}