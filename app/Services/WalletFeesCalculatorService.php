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



             $fee = Fee::where('transaction_type_id', $transaction_type)
                ->where('minimum_daily', '<=', $amount)
                ->where('maximum_daily', '>=', $amount)
                ->first();


            //return $fee->fee;

            if (!$fee) {
                return false;
            }

            $fees_charged = 0;
            $tax = 0;


            /*
             * ZIMSWITCH FEES DEBIT
             * */
            if ($fee->fee_type == 'FIXED') {
                $fees_charged += $fee->fee;
                $revenue_fees = $fee->fee;
            }
            if ($fee->fee_type == 'PERCENTAGE') {
                $fees_charged = $amount * ($fee->fee / 100);
                $revenue_fees = $amount * ($fee->fee / 100);
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


            $rate = 100 - $fee->agent_fee;
            $discount = $amount * ($fee->biller_discount/100);


            return array(

                'fee'                       => number_format((float)$revenue_fees, 4, '.', ''),
                'tax'                       => number_format((float)$tax, 4, '.', ''),
                'inclusive_agent_portion'   => number_format((float)$discount * ($fee->agent_fee / 100), 4, '.', ''),
                'inclusive_revenue_portion' => number_format((float)$discount * ($rate / 100), 4, '.', ''),
                'exclusive_revenue_portion' => number_format((float)$revenue_fees * ($rate / 100), 4, '.', ''),
                'exclusive_agent_portion'   => number_format((float)$revenue_fees * ($fee->agent_fee/ 100), 4, '.', ''),
                'individual_fee'            => $discount,
                'maximum_daily'             => $fee->maximum_daily,
                'biller_dr'                 => $fee->biller_discount,
                'fee_type'                  => $fee->type,
            );




    }

    public static function getClientId($account){
        $inital_trimmed_account = substr($account, 0, -1);
        $client_id = substr($inital_trimmed_account, 4);

        return $client_id;
    }















}