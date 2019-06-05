<?php
namespace App\Http\Controllers;


use App\COS;
use App\Devices;
use App\Merchant;
use App\Services\BalanceEnquiryService;
use App\Services\ApiTokenValidity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;


class LaunchController extends Controller
{
    /**
     * Index login controller
     *
     * When user success login will retrive callback as api_token
     */
    public function index(Request $request)
    {




            //API INPUT VALIDATION
            $validator = $this->launch($request->all());
            if ($validator->fails()) {
                return response()->json(['code' => '99', 'description' => $validator->errors()]);
            }


            $result =  Devices::all()->where('imei' ,$request->imei)->count();

           if ($result == 0){

               return response([

                   'code' =>'58',
                   'description' => 'Devices device does not exists',
               ]);
           }

            $status = Devices::where('imei', $request->imei)->get();

            foreach ($status as $state) {
                //Check Device State
                if ($state->state == '0') {

                    return response(['code' => '58', 'Description' => 'Devices is not active']);

                }else{


                    $search_res =  $result =  Devices::all()->where('imei' ,$request->imei);

                    foreach ($search_res as $ress){

                        $merchant =  Merchant::where('id', $ress->merchant_id)->first();
                        $menu = COS::where('id',$merchant->class_of_service_id)->first();

                         return response([
                             'code' => '00',
                             'description' => 'ACTIVE',
                             'merchant_profile' => $merchant,
                             'menu' => $menu,

                         ]);
                    }



                }



            }

        }







    protected function launch(Array $data)
    {
        return Validator::make($data, [
            'imei' => 'required',
        ]);
    }









}