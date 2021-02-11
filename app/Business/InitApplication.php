<?php
/**
 * Created by PhpStorm.
 * User: namar
 * Date: 10/9/2018
 * Time: 11:32 AM
 */

namespace App\Business;


use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

trait InitApplication
{
    private function init()
    {
        info("InitApplication.init");
        // fetch the licence company details
        $support = date("Y");

        Cache::forever('business.support', $support);
        //Cache::put("initApplication", true, 30);

        // LEAVE TYPES
        $leaveTypes = array();
        $leaveTypes[0] = "Vacation Leave";
        $leaveTypes[1] = "Maternity Leave";
        $leaveTypes[2] = "Sick Leave";
        $leaveTypes[3] = "Study Leave";
        $leaveTypes[4] = "Special Leave";

        Cache::forever(config('business.leave_types'), $leaveTypes);
        info("InitApplication.leave");
        // MARITAL STATUS
        $maritalStatus = array();
        $maritalStatus[0] = "Single";
        $maritalStatus[1] = "Married";
        $maritalStatus[2] = "Divorced";
        $maritalStatus[3] = "Widowed";
        Cache::forever(config('business.marital_status'), $maritalStatus);
        info("InitApplication.marital status");

        // LOAN TYPES
        $loanTypes = array();
        $loanTypes[0] = "Straight Line Method";
        $loanTypes[1] = "Running Balance Method";
        Cache::forever(config('business.loan_types'), $loanTypes);
        info("InitApplication.loan types");
        return true;
    }
}
