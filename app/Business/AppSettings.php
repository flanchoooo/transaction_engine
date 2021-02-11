<?php
/**
 * Created by PhpStorm.
 * User: namar
 * Date: 25-Oct-18
 * Time: 12:24 PM
 */

namespace App\Business;


use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;

trait AppSettings
{

    public function injectSettingsIntoViews(){
        $params = $this->getAppSettings();
        foreach ($params as $key => $value){
            View::share($key, $value);
        }
    }

    public function getAppSettings()
    {
        return [
            'marital_status' => Cache::get(config('business.marital_status')),
            'leave_types' => Cache::get(config('business.leave_types')),
            'loan_types' => Cache::get(config('business.loan_types'))
        ];
    }

}
