<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\LandingSetting;

class LandingController extends Controller
{
    public function index()
    {
        $landing = LandingSetting::first();

        return view('landing.index', compact('landing'));
    }
}
