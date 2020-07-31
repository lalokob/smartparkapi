<?php

namespace App\Http\Controllers;

class TestController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function test()
    {
        return response()->json(['message' => 'Successfully TEST!!!']);
    }
}
