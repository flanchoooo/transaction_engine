<?php
/**
 * Created by PhpStorm.
 * User: deant
 * Date: 7/23/18
 * Time: 6:57 PM
 */

namespace App\Services;


use App\Fee;



class WalletFeesCalculatorService
{
    public static function calculateFees($amount,  $transaction_type){

        $fee = Fee::where('transaction_id', SEND_MONEY)
            ->where('minimum_amount', '<=',$amount)
            ->where('maximum_amount', '>=', $amount)
            ->first();

        if(!isset($fee)){
            return array('code'=> '01');
        }

        $revenueFeesPercentage   = $amount * ($fee->percentage_fee / 100);
        $totalRevenueFees        = $fee->fixed_fee + $revenueFeesPercentage;
        $taxFeesPercentage       = $amount * ($fee->tax_percentage / 100);
        $totalTaxFees            = $fee->tax_fixed + $taxFeesPercentage;
        $feesCharged             = $totalRevenueFees + $totalTaxFees;

        return array(
            'code'           => '00',
            'fees_charged'   => $feesCharged,
            'revenue_fees'   => $totalRevenueFees,
            'tax'            => $totalTaxFees
        );

    }
















}