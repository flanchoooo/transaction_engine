<?php
/**
 * Created by PhpStorm.
 * User: deant
 * Date: 7/23/18
 * Time: 6:57 PM
 */

namespace App\Services;


use App\COS;
use App\Fee;
use App\Merchant;

class FeesCalculatorService
{
    public static function calculateFees($amount, $cash_back_amount, $transaction_type, $merchant_id,$account){



       $fee = Fee::where('transaction_type_id', $transaction_type)
            ->where('minimum_daily', '<=', $amount)
            ->where('maximum_daily', '>=', $amount)
            ->first();



        $mdr = Merchant::where('id', $merchant_id)->first();

        //return $fee;

        if (!$fee) {
            return false;
        }

        $fees_charged = 0;
        $zimswitch_fee = 0;
        $tax = 0;
        $acquirer_fee = 0;
        $interchange_fee = 0;
        $cash_back_fee = 0;

        /*
         * ZIMSWITCH FEES DEBIT
         * */
        if ($fee->zimswitch_fee_type == 'FIXED') {
            $fees_charged += $fee->zimswitch_fee;
            $zimswitch_fee = $fee->zimswitch_fee;
        }
        if ($fee->zimswitch_fee_type == 'PERCENTAGE') {
            $fees_charged = $amount * ($fee->zimswitch_fee / 100);
            $zimswitch_fee = $amount * ($fee->zimswitch_fee / 100);
        }

        /*
         * TAX DEBIT
         * */
        if ($fee->tax_type == 'FIXED') {
            $fees_charged += $fee->tax;
            $tax = $fee->tax;
        }
        if ($fee->tax_type == 'PERCENTAGE') {
            $fees_charged += $amount * ($fee->tax / 100);
            $tax = $amount * ($fee->tax / 100);
        }

        /*
         * Acquirer Debit
         * */
        if ($fee->acquirer_fee_type == 'FIXED') {
            $fees_charged += $fee->acquirer_fee;
            $acquirer_fee = $fee->acquirer_fee;
        }
        if ($fee->acquirer_fee_type == 'PERCENTAGE') {
            $fees_charged += $amount * ($fee->acquirer_fee / 100);
            $acquirer_fee = $amount * ($fee->acquirer_fee / 100);
        }

        /*
         * Interchange Fee
         * */
        if ($fee->interchange_fee_type == 'FIXED') {
            $interchange_fee = $fee->interchange_fee;
        }
        if ($fee->interchange_fee_type == 'PERCENTAGE') {
            $interchange_fee = $amount * $fee->interchange_fee;
        }
        /*
         * Cashback Fee
         * */

        if ($fee->cashback_fee_type == 'PERCENTAGE') {
            $fees_charged += $cash_back_amount * ($fee->cash_back_fee / 100);
            $cash_back_fee =  $cash_back_amount *  ($fee->cash_back_fee /100);
        }


        /*
         * Cashback Fee
         * */

        if ($merchant_id) {

            $merchant_service_commission = (string)$amount * ($mdr->mdr / 100);
        }

        $account_class = substr($account, 3, 3);
        if ($account_class == '201') {
            return array(
                'fees_charged'          => $fees_charged - $acquirer_fee,
                'zimswitch_fee'         => $zimswitch_fee,
                'interchange_fee'       => $interchange_fee,
                'acquirer_fee'          => 0,
                'cash_back_fee'         => $cash_back_fee,
                'tax'                   => $tax,
                'mdr'                   => $merchant_service_commission,
                'maximum_daily'         => $fee->maximum_daily,
                'transaction_count'     => $fee->transaction_count,
                'minimum_balance'       => 0,
                'max_daily_limit'       => $fee->max_daily_limit,

            );
        }


        if ($account_class == '202') {
            return array(
                'fees_charged'          => $fees_charged,
                'zimswitch_fee'         => $zimswitch_fee,
                'interchange_fee'       => $interchange_fee,
                'acquirer_fee'          => $acquirer_fee,
                'cash_back_fee'         => $cash_back_fee,
                'tax'                   => $tax,
                'mdr'                   => $merchant_service_commission,
                'maximum_daily'         => $fee->maximum_daily,
                'transaction_count'     => $fee->transaction_count,
                'minimum_balance'       => 30,
                'max_daily_limit'       => $fee->max_daily_limit,

            );
        }


        if ($account_class == '204') {
            return array(
                'fees_charged'          => $fees_charged,
                'zimswitch_fee'         => $zimswitch_fee,
                'interchange_fee'       => $interchange_fee,
                'acquirer_fee'          => $acquirer_fee,
                'cash_back_fee'         => $cash_back_fee,
                'tax'                   => $tax,
                'mdr'                   => $merchant_service_commission,
                'maximum_daily'         => $fee->maximum_daily,
                'transaction_count'     => $fee->transaction_count,
                'minimum_balance'       => 20,
                'max_daily_limit'       => $fee->max_daily_limit,

            );
        }


        return array(
            'fees_charged'          => $fees_charged,
            'zimswitch_fee'         => $zimswitch_fee,
            'interchange_fee'       => $interchange_fee,
            'acquirer_fee'          => $acquirer_fee,
            'cash_back_fee'         => $cash_back_fee,
            'tax'                   => $tax,
            'mdr'                   => $merchant_service_commission,
            'maximum_daily'         => $fee->maximum_daily,
            'transaction_count'     => $fee->transaction_count,
            'minimum_balance'       => 30,
            'max_daily_limit'       => $fee->max_daily_limit,

        );


    }

    public static function getClientId($account){
        $inital_trimmed_account = substr($account, 0, -1);
        $client_id = substr($inital_trimmed_account, 4);

        return $client_id;
    }















}