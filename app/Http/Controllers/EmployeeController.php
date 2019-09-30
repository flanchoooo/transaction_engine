<?php
namespace App\Http\Controllers;



use App\Devices;
use App\Employee;
use App\Logs;
use App\TransactionType;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;


class EmployeeController extends Controller
{
    /**
     * Index login controller
     *
     * When user success login will retrive callback as api_token
     */

    public function employee_register(Request $request)
    {


        $validator = $this->register_employee_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }


        try {
            Employee::create([

                'pin' => Hash::make($request->pin),
                'username' => $request->user,
                'merchant_id' => $request->merchant_id,
                'mobile' => $request->mobile,
                'state' => 0,

            ]);
        } catch (QueryException $queryException){

            return response([

                'code' => '01',
                'description' => 'Employee profile already exists'
            ]) ;

        }


        Logs::create([
            'description' => "Employee profile successfully created",
            'user' => $request->created_by,

        ]);


        return response([

            'code' => '00',
            'description' => 'Employee profile successfully created'
        ]) ;











    }

    public function employee_login(Request $request)
    {

        $validator = $this->employee_login_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }

        $employee =  Employee::where('mobile',$request->mobile)->get()->first();
        $device =  Devices::where('imei', $request->imei)->get()->first();

        if($employee->state == 0){

            return response([

                'code' => '01',
                'description' => 'Account blocked contact support.',

            ]);
        }

        if(!isset($employee)){

            return response([

                'code' => '01',
                'description' => 'Authentication Failed',

            ]);

        }



        if (!Hash::check($request->pin, $employee->pin)){

            return response([

                'code' => '02',
                'description' => 'Authentication Failed',

            ]);

        }



        if(! isset($device)){

            return response([

                'code' => '02',
                'description' => 'Invalid login request',

            ]);

        }


        if($employee->merchant_id != $device->merchant_id){

            return response([

                'code' => '03',
                'description' => 'Invalid credentials for merchant profile',

            ]);

        }


        $employee->login_state = '1';
        $employee->imei = $request->imei;
        $employee->save();

        return response([

            'code' => '00',
            'description' => 'Login successful.',

        ]);














    }


    public function employee_logout(Request $request)
    {

        $validator = $this->employee_login_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }

        $employee =  Employee::where('imei', $request->imei)->get()->first();

        if(!isset($employee)){

            return response([

                'code' => '00',
                'description' => 'User already logged out.',

            ]);

        }

        $employee->login_state = '0';
        $employee->imei = NULL;
        $employee->save();

        return response([

            'code' => '00',
            'description' => 'Logout successful.',

        ]);



    }

    public function change_status(Request $request)
    {

        $validator = $this->employee_change_status_validator($request->all());
        if ($validator->fails()) {
            return response()->json(['code' => '99', 'description' => $validator->errors()]);
        }

        $employee =  Employee::where('mobile', $request->mobile)->get()->first();

        if(! isset($employee)){

            return response([

                'code' => '00',
                'description' => 'User not found.',

            ]);
        }

        $employee->state = $request->state;
        $employee->imei = NULL;
        $employee->save();

        return response([

            'code' => '00',
            'description' => 'Status changed successfully',

        ]);



    }


    protected function employee_login_validator(Array $data)
    {
        return Validator::make($data, [
            'pin' => 'required',
            'mobile' => 'required',
            'imei' => 'required',
        ]);
    }

    protected function register_employee_validator(Array $data)
    {
        return Validator::make($data, [
            'pin' => 'required',
            'user' => 'required',
            'merchant_id' => 'required',
            'mobile' => 'required',
            'created_by' => 'required',
        ]);
    }

    protected function employee_logout_validator(Array $data)
    {
        return Validator::make($data, [

            'imei' => 'required',

        ]);
    }

    protected function employee_change_status_validator(Array $data)
    {
        return Validator::make($data, [

            'mobile' => 'required',
            'state' => 'required',

        ]);
    }









}