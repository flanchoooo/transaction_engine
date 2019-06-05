<?php

namespace App\Jobs;

use App\Transaction;
use http\Env\Request;

class ExampleJob extends Job
{
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(Request $request)
    {


       return 'flavian';



    }


}
