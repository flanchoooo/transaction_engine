<?php
namespace App\Http\Controllers;




use App\Services\FeesCalculatorService;
use App\Services\TokenService;
use App\Services\WalletFeesCalculatorService;
use App\Transactions;
use App\Wallet;
use App\WalletTransactions;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;


class ReversalController extends Controller
{
    /**
     * Index login controller
     *
     * When user success login will retrive callback as api_token
     */


    public function reversal(Request $request)
    {

        $validator = $this->reversals_validation($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }


           $wallet_batch  = WalletTransactions::whereBatchId($request->transaction_batch_id)->first();

       if(isset($wallet_batch)){
           if($wallet_batch->reversed =='REVERSED'){
               return response([
                   'code'          => '01',
                   'description'   => 'Transaction already reversed.'
               ]);
           }


           DB::beginTransaction();
           try {

               //Source Account
               $source      = Wallet::whereMobile($wallet_batch->account_debited);
               $revenue     = Wallet::whereMobile(WALLET_REVENUE);
               $tax         = Wallet::whereMobile(WALLET_TAX);
               $destination = Wallet::whereMobile($wallet_batch->account_credited);

              return  $destination_mobile = $destination->lockForUpdate()->first();

               if( $wallet_batch->txn_type_id == ZIPIT_SEND){

                    $zipit_fees = FeesCalculatorService::calculateFees(
                        $wallet_batch->transaction_amount,
                       '0.00',
                       ZIPIT_SEND,
                       HQMERCHANT
                   );

                   if($destination_mobile->balance <  $wallet_batch->transaction_amount){
                       return response([
                           'code'          => '100',
                           'description'   => 'Source account does not have sufficient funds to perform a reversal',
                       ]);

                   }

                   //Refund total debited.
                    $total = $wallet_batch->transaction_amount + $zipit_fees['acquirer_fee'] + $zipit_fees['tax'] + $zipit_fees['zimswitch_fee'] ;
                   $source_mobile = $source->lockForUpdate()->first();
                   $source_mobile->balance += $total;
                   $source_mobile->save();

                   $revenue_mobile = $revenue->lockForUpdate()->first();
                   $revenue_mobile->balance -= $zipit_fees['acquirer_fee'];
                   $revenue_mobile->save();

                   $tax_mobile = $tax->lockForUpdate()->first();
                   $tax_mobile->balance -=$zipit_fees['tax'];
                   $tax_mobile->save();


                   $destination_mobile->balance -= $wallet_batch->transaction_amount + $zipit_fees['zimswitch_fee'] ;
                   $destination_mobile->save();

                   $wallet_batch->reversed = 'REVERSED';
                   $wallet_batch->transaction_status = '3';
                   $wallet_batch->description = 'Original transaction was successfully reversed.';
                   $wallet_batch->save();

                   DB::commit();

                   return response([
                       'code'          => '000',
                       'description'   => 'Successfully reversed ZIPIT send transaction'
                   ]);

               }

               if($wallet_batch->txn_type_id == BALANCE_ON_US){
                    $balance_onus_fees = FeesCalculatorService::calculateFees(
                       '0.00', '0.00', BALANCE_ON_US,
                       $wallet_batch->merchant_id
                   );

                   if($destination_mobile->balance <  $wallet_batch->transaction_amount){
                       return response([
                           'code'          => '100',
                           'description'   => 'Source account does not have sufficient funds to perform a reversal',
                       ]);

                   }
                   $source_mobile = $source->lockForUpdate()->first();
                   $source_mobile->balance += $balance_onus_fees['acquirer_fee'];
                   $source_mobile->save();


                   $destination_mobile->balance -= $balance_onus_fees['acquirer_fee'];
                   $destination_mobile->save();

                   $wallet_batch->reversed = 'REVERSED';
                   $wallet_batch->transaction_status = '3';
                   $wallet_batch->description = 'Original transaction was successfully reversed.';
                   $wallet_batch->save();


                   DB::commit();

                   return response([
                       'code' => '000',
                       'description' => 'Successfully reversed balance on us transaction.'
                   ]);


               }

               if($wallet_batch->txn_type_id == BALANCE_ENQUIRY_OFF_US){
                    $balance_onus_fees = FeesCalculatorService::calculateFees(
                       '0.00', '0.00', BALANCE_ENQUIRY_OFF_US,
                       HQMERCHANT
                   );

                   if($destination_mobile->balance <  $wallet_batch->transaction_amount){
                       return response([
                           'code'          => '100',
                           'description'   => 'Source account does not have sufficient funds to perform a reversal',
                       ]);

                   }
                   $source_mobile = $source->lockForUpdate()->first();
                   $source_mobile->balance += $balance_onus_fees['zimswitch_fee'];
                   $source_mobile->save();


                   $destination_mobile->balance -= $balance_onus_fees['zimswitch_fee'];
                   $destination_mobile->save();

                   $wallet_batch->reversed = 'REVERSED';
                   $wallet_batch->transaction_status = '3';
                   $wallet_batch->description = 'Original transaction was successfully reversed.';
                   $wallet_batch->save();


                   DB::commit();

                   return response([
                       'code' => '000',
                       'description' => 'Successfully reversed balance remote on us transaction.'
                   ]);


               }

               if($wallet_batch->txn_type_id == PURCHASE_ON_US){
                    $purchase_fees = FeesCalculatorService::calculateFees(
                       $wallet_batch->transaction_amount, '0.00', PURCHASE_ON_US,
                       $wallet_batch->merchant_id
                   );

                     $less_mdr = $wallet_batch->transaction_amount - $purchase_fees['mdr'];
                     $less_revenue = $purchase_fees['mdr'] + $purchase_fees['acquirer_fee'];
                     $less_fees  = $purchase_fees['tax'] + $purchase_fees['acquirer_fee'];
                     $credit_source =   $wallet_batch->transaction_amount + $less_fees;


                   if($destination_mobile->balance <  $less_mdr){
                       return response([
                           'code'          => '100',
                           'description'   => 'Source account does not have sufficient funds to perform a reversal',
                       ]);

                   }


                   $source_mobile = $source->lockForUpdate()->first();
                   $source_mobile->balance += $credit_source;
                   $source_mobile->save();

                   $tax_mobile = $tax->lockForUpdate()->first();
                   $tax_mobile->balance -= $purchase_fees['tax'];
                   $tax_mobile->save();

                   $revenue_mobile = $revenue->lockForUpdate()->first();
                   $revenue_mobile->balance -= $less_revenue;
                   $revenue_mobile->save();


                   $destination_mobile->balance -= $less_mdr;
                   $destination_mobile->save();

                   $wallet_batch->reversed = 'REVERSED';
                   $wallet_batch->transaction_status = '3';
                   $wallet_batch->description = 'Original transaction was successfully reversed.';
                   $wallet_batch->save();



                   DB::commit();

                   return response([
                       'code' => '000',
                       'description' => 'Successfully reversed purchase on us transaction.'
                   ]);


               }

               if($wallet_batch->txn_type_id == PURCHASE_OFF_US){
                   $purchase_fees = FeesCalculatorService::calculateFees(
                       $wallet_batch->transaction_amount, '0.00', PURCHASE_OFF_US,
                       HQMERCHANT
                   );

                   $less_mdr =  $less_revenue =  - $purchase_fees['interchange_fee'] + $purchase_fees['acquirer_fee'] + $wallet_batch->transaction_amount ;
                   $less_revenue = $purchase_fees['interchange_fee'];
                   $less_fees  = $purchase_fees['tax'] + $purchase_fees['acquirer_fee'];
                   $credit_source =   $wallet_batch->transaction_amount + $less_fees;


                   if($destination_mobile->balance <  $less_mdr){
                       return response([
                           'code'          => '100',
                           'description'   => 'Source account does not have sufficient funds to perform a reversal',
                       ]);

                   }


                   $source_mobile = $source->lockForUpdate()->first();
                   $source_mobile->balance += $credit_source;
                   $source_mobile->save();

                   $tax_mobile = $tax->lockForUpdate()->first();
                   $tax_mobile->balance -= $purchase_fees['tax'];
                   $tax_mobile->save();

                   $revenue_mobile = $revenue->lockForUpdate()->first();
                   $revenue_mobile->balance -= $less_revenue;
                   $revenue_mobile->save();


                   $destination_mobile->balance -= $less_mdr;
                   $destination_mobile->save();

                   $wallet_batch->reversed = 'REVERSED';
                   $wallet_batch->transaction_status = '3';
                   $wallet_batch->description = 'Original transaction was successfully reversed.';
                   $wallet_batch->save();



                   DB::commit();

                   return response([
                       'code' => '000',
                       'description' => 'Successfully reversed balance purchase remote on us transaction.'
                   ]);


               }

               if($wallet_batch->txn_type_id == ZIPIT_RECEIVE){

                   if($destination_mobile->balance <  $wallet_batch->transaction_amount){
                       return response([
                           'code'          => '100',
                           'description'   => 'Source account does not have sufficient funds to perform a reversal',
                       ]);

                   }


                   $source_mobile = $source->lockForUpdate()->first();
                   $source_mobile->balance += $wallet_batch->transaction_amount;
                   $source_mobile->save();

                   $destination_mobile->balance -= $wallet_batch->transaction_amount;;
                   $destination_mobile->save();

                   $wallet_batch->reversed = 'REVERSED';
                   $wallet_batch->transaction_status = '3';
                   $wallet_batch->description = 'Original transaction was successfully reversed.';
                   $wallet_batch->save();

                   DB::commit();

                   return response([
                       'code'          => '000',
                       'description'   => 'Successfully reversed ZIPIT receive transaction'
                   ]);

                   //return $wallet_batch;
               }

               if($wallet_batch->txn_type_id == SEND_MONEY){

                   $wallet_fees = WalletFeesCalculatorService::calculateFees(
                       $wallet_batch->transaction_amount,
                       $wallet_batch->txn_type_id);

                   if($destination_mobile->balance <  $wallet_batch->transaction_amount){
                       return response([
                           'code'          => '100',
                           'description'   => 'Source account does not have sufficient funds to perform a reversal',
                       ]);

                   }

                   $total = $wallet_batch->transaction_amount + $wallet_fees['fee'] + $wallet_fees['tax'] ;
                   $source_mobile = $source->lockForUpdate()->first();
                   $source_mobile->balance += $total;
                   $source_mobile->save();

                   $revenue_mobile = $revenue->lockForUpdate()->first();
                   $revenue_mobile->balance -= $wallet_fees['fee'];
                   $revenue_mobile->save();

                   $tax_mobile = $tax->lockForUpdate()->first();
                   $tax_mobile->balance -=$wallet_fees['tax'];
                   $tax_mobile->save();


                   $destination_mobile->balance -=$wallet_batch->transaction_amount;
                   $destination_mobile->save();

                   $wallet_batch->reversed = 'REVERSED';
                   $wallet_batch->description = 'Original transaction was successfully reversed.';
                   $wallet_batch->save();



                   DB::commit();


                   return response([
                       'code'          => '000',
                       'description'   => 'Successfully reversed send money transaction'
                   ]);

               }

               if($wallet_batch->txn_type_id == CASH_IN){

                   $wallet_fees = WalletFeesCalculatorService::calculateFees(
                       $wallet_batch->transaction_amount,
                       $wallet_batch->txn_type_id);

                   if($destination_mobile->balance <  $wallet_batch->transaction_amount){
                       return response([
                           'code'          => '100',
                           'description'   => 'Source account does not have sufficient funds to perform a reversal',
                       ]);

                   }


                   $source_mobile = $source->lockForUpdate()->first();
                   $source_mobile->balance += $wallet_batch->transaction_amount;
                   $source_mobile->commissions -=$wallet_fees['fee'];
                   $source_mobile->save();

                   $revenue_mobile = $revenue->lockForUpdate()->first();
                   $revenue_mobile->balance += $wallet_fees['fee'];
                   $revenue_mobile->save();

                   $destination_mobile->balance -= $wallet_batch->transaction_amount;
                   $destination_mobile->save();

                   $wallet_batch->reversed = 'REVERSED';
                   $wallet_batch->description = 'Original transaction was successfully reversed.';
                   $wallet_batch->save();


                   DB::commit();


                   return response([
                       'code'          => '000',
                       'description'   => 'Successfully reversed cash in transaction'
                   ]);

               }

               if($wallet_batch->txn_type_id == CASH_OUT){

                    $wallet_fees = WalletFeesCalculatorService::calculateFees(
                       $wallet_batch->transaction_amount,
                       CASH_OUT);

                   if($destination_mobile->balance <  $wallet_batch->transaction_amount){
                       return response([
                           'code'          => '100',
                           'description'   => 'Source account does not have sufficient funds to perform a reversal',
                       ]);

                   }

                    //Credit Source
                   $source_mobile = $source->lockForUpdate()->first();
                   $source_mobile->balance -= $wallet_batch->transaction_amount;
                   $source_mobile->commissions -=$wallet_fees['exclusive_agent_portion'];
                   $source_mobile->save();

                   $revenue_mobile = $revenue->lockForUpdate()->first();
                   $revenue_mobile->balance -= $wallet_fees['exclusive_revenue_portion'];
                   $revenue_mobile->save();

                   $credit = $wallet_batch->transaction_amount + $wallet_fees['fee'];
                   $destination_mobile->balance -= $credit;
                   $destination_mobile->save();

                   $wallet_batch->reversed = 'REVERSED';
                   $wallet_batch->description = 'Original transaction was successfully reversed.';
                   $wallet_batch->save();


                   DB::commit();


                   return response([
                       'code'          => '000',
                       'description'   => 'Successfully reversed cash out transaction'
                   ]);

               }




            //return $wallet_batch;


               /*
                return  $wallet_fees = WalletFeesCalculatorService::calculateFees(
                   $wallet_batch->transaction_amount,
                   $wallet_batch->txn_type_id

               );



                if($wallet_fees['fee_type'] == 'INCLUSIVE'){
                    $transaction_amount = $wallet_batch->transaction_amount - $wallet_fees['individual_fee'];
                }else{
                    $transaction_amount = $wallet_batch->transaction_amount;
                }


               if($destination_mobile->balance < $transaction_amount){
                   return response([
                       'code'          => '01',
                       'description'   => 'Source account does not have sufficient funds to perform a reversal',
               ]);

               }


               $source_mobile = $source->lockForUpdate()->first();
               if(isset($source_mobile->merchant_id)){
                   if($wallet_fees['fee_type'] == 'EXCLUSIVE') {
                       $source_mobile->balance += $wallet_batch->transaction_amount
                           + $wallet_fees['exclusive_agent_portion']
                           + $wallet_fees['exclusive_revenue_portion'] ;
                       $source_mobile->commissions -= $wallet_fees['exclusive_agent_portion'];
                       $source_mobile->save();

                       $revenue_mobile = $revenue->lockForUpdate()->first();
                       $revenue_mobile->balance -= $wallet_fees['exclusive_revenue_portion'];
                       $revenue_mobile->save();

                       $tax_mobile = $tax->lockForUpdate()->first();
                       $tax_mobile->balance -= $wallet_fees['tax'];
                       $tax_mobile->save();

                       $destination_mobile->balance -= $wallet_batch->transaction_amount;
                       $destination_mobile->save();

                       $wallet_batch->reversed = 'REVERSED';
                       $wallet_batch->description = 'Original transaction was successfully reversed.';
                       $wallet_batch->save();

                       DB::commit();


                       return response([

                           'code' => '000',
                           'description' => 'Success'


                       ]);

                   }

                   if($wallet_fees['fee_type'] == 'INCLUSIVE'){


                       $source_mobile->balance += $transaction_amount + $wallet_fees['inclusive_agent_portion'] + $wallet_fees['inclusive_revenue_portion'] ;
                       $source_mobile->commissions -= $wallet_fees['inclusive_agent_portion'];
                       $source_mobile->save();

                       $revenue_mobile = $revenue->lockForUpdate()->first();
                       $revenue_mobile->balance -= $wallet_fees['inclusive_revenue_portion'];
                       $revenue_mobile->save();

                       $tax_mobile = $tax->lockForUpdate()->first();
                       $tax_mobile->balance -= $wallet_fees['tax'];
                       $tax_mobile->save();

                       $destination_mobile->balance -= $transaction_amount;
                       $destination_mobile->save();

                       $wallet_batch->reversed = 'REVERSED';
                       $wallet_batch->description = 'Original transaction was successfully reversed.';
                       $wallet_batch->save();

                       //return 'Reverse Success';
                       DB::commit();


                       return response([

                           'code' => '000',
                           'description' => 'Success'


                       ]);

                   }

               }




               //Refund total debited.
                $total = $wallet_batch->transaction_amount + $wallet_fees['fee'] + $wallet_fees['tax'] ;
                $source_mobile = $source->lockForUpdate()->first();
                $source_mobile->balance += $total;
                $source_mobile->save();

                $revenue_mobile = $revenue->lockForUpdate()->first();
                $revenue_mobile->balance -= $wallet_fees['fee'];
                $revenue_mobile->save();

                $tax_mobile = $tax->lockForUpdate()->first();
                $tax_mobile->balance -=$wallet_fees['tax'];
                $tax_mobile->save();


                $destination_mobile->balance -=$wallet_batch->transaction_amount;
                $destination_mobile->save();

               $wallet_batch->reversed = 'REVERSED';
               $wallet_batch->description = 'Original transaction was successfully reversed.';
               $wallet_batch->save();



               DB::commit();


               return response([
                   'code'          => '000',
                   'description'   => 'Success'
               ]);

               */

           } catch (\Exception $e) {

              return $e;

               DB::rollBack();
               Log::debug('Account Number:'.$request->account_number.' '. $e);
               WalletTransactions::create([
                   'txn_type_id'       => SEND_MONEY,
                   'tax'               => '0.00',
                   'revenue_fees'      => '0.00',
                   'interchange_fees'  => '0.00',
                   'zimswitch_fee'     => '0.00',
                   'transaction_amount'=> '0.00',
                   'total_debited'     => '0.00',
                   'total_credited'    => '0.00',
                   'batch_id'          => '',
                   'switch_reference'  => '',
                   'merchant_id'       => '',
                   'transaction_status'=> 0,
                   'pan'               => '',
                   'description'       => 'Transaction was reversed for mobbile:' . $request->account_number,

               ]);

               return response([
                   'code' => '400',
                   'description' => 'Transaction was reversed',

               ]);
           }



       }





        try {


            $authentication = TokenService::getToken();

            $client = new Client();
            $result = $client->post(env('BASE_URL') . '/api/reversals', [

                'headers' => ['Authorization' => $authentication, 'Content-type' => 'application/json',],
                'json' => [
                    'branch_id' => '001',
                    'transaction_batch_id' => $request->transaction_batch_id,
                ]
            ]);

       // return $response = $result->getBody()->getContents();
        $response = json_decode($result->getBody()->getContents());

        if($response->code != '00'){

            Transactions::create([

                'txn_type_id'         => REVERSAL,
                'tax'                 => '0.00',
                'revenue_fees'        => '0.00',
                'interchange_fees'    => '0.00',
                'zimswitch_fee'       => '0.00',
                'transaction_amount'  => '0.00',
                'total_debited'       => '0.00',
                'total_credited'      => '0.00',
                'batch_id'            => '',
                'switch_reference'    => '',
                'merchant_id'         => '',
                'transaction_status'  => 0,
                'account_debited'     => '',
                'pan'                 => '',
                'description'         => 'Reversal for batch'.$request->transaction_batch_id,


            ]);
        }

            Transactions::create([

                'txn_type_id'         => REVERSAL,
                'tax'                 => '0.00',
                'revenue_fees'        => '0.00',
                'interchange_fees'    => '0.00',
                'zimswitch_fee'       => '0.00',
                'transaction_amount'  => '0.00',
                'total_debited'       => '0.00',
                'total_credited'      => '0.00',
                'batch_id'            => '',
                'switch_reference'    => '',
                'merchant_id'         => '',
                'transaction_status'  => 1,
                'account_debited'     => '',
                'pan'                 => '',
                'description'         => 'Reversal for batch'.$request->transaction_batch_id,


            ]);


            return response([

                'code' => '00',
                'description' => 'Success'


            ]);




        }catch (RequestException $e) {

            Transactions::create([

                'txn_type_id'         => REVERSAL,
                'tax'                 => '0.00',
                'revenue_fees'        => '0.00',
                'interchange_fees'    => '0.00',
                'zimswitch_fee'       => '0.00',
                'transaction_amount'  => '0.00',
                'total_debited'       => '0.00',
                'total_credited'      => '0.00',
                'batch_id'            => '',
                'switch_reference'    => '',
                'merchant_id'         => '',
                'transaction_status'  => 0,
                'account_debited'     => '',
                'pan'                 => '',
                'description'         => 'Failed to process reversal, batch id not found.',


            ]);

            if ($e->hasResponse()) {
               // $exception = (string)$e->getResponse()->getBody();
               // $exception = json_decode($exception);

                return array('code' => '91',
                    'description' => 'Batch id not found.');


            }

        }

    }




    protected function reversals_validation(Array $data)
    {
        return Validator::make($data, [
            'transaction_batch_id' => 'required',
        ]);
    }

























    protected function reversal_data(Array $data)
    {
        return Validator::make($data, [

            'batch_id' => 'required',

        ]);
    }







}