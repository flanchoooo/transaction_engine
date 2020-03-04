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
use App\IBFees;
use App\Merchant;

class IBFeesCalculatorService
{
    public static function calculateFees($amount, $product, $account, $destination){

        $fee = IBFees::whereProductId($product)
            ->where('minimum_limit', '<=', $amount)
            ->where('maximum_limit', '>=', $amount)
            ->first();

        //return $fee;

        if (!$fee) {
            return false;
        }

        $tax = $fee->tax_fee;
        if ($fee->tax_type == 'PERCENTAGE') {
            $tax = $amount * ($fee->tax_fee / 100);
        }

        //00120200012431

        /*
         * Removes tax from same client id accounts
         * */
        $source_trimmed = substr($account,2);
        $destination_trimmed = substr($destination,2);
        if (self::getClientId($source_trimmed) == self::getClientId($destination_trimmed)) {
            $tax = 0;
        }

        /*
         * Removes charges from staff accounts
         * */
        $account_class = substr($account, 3, 3);

        if ($account_class == '201') {
            return array('fees_charged' => $tax,
                'revenue_fee'  => 0,
                'tax_fee'      => $tax);
        }


        /*
         * Fixed fees charges
         * */
        if ($fee->fees_type == 'FIXED') {
            $fees_charged = $fee->revenue_fee + $tax;

            return array('fees_charged' => $fees_charged,
                'revenue_fee'  => $fee->revenue_fee,
                'tax_fee'      => $tax);
        }

        /*
         * Percentage Fees Charges
         * */
        if ($fee->fees_type == 'PERCENTAGE') {

            $revenue_fees = $amount * ($fee->revenue_fee / 100);

            $fees_charged = $revenue_fees + $tax;

            return array('fees_charged' => $fees_charged,
                'revenue_fee'  => $revenue_fees,
                'tax_fee'      => $tax);
        }

    }

    public static function getClientId($account){
        $inital_trimmed_account = substr($account, 4);
        $client_id = substr($inital_trimmed_account, 0,7);

        return $client_id;
    }














}