<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class HomeController extends Controller
{
    public function index()
    {
        return view('home.index');
    }

    public function Indication(Request $request, $userId)
    {
        $results = User::where('uuid', $userId)->first();
        if ($results) {
            $sponId = $results->uuid;
            $name = $results->name;
            Session::put('sponId', $sponId);
            Session::put('sponsorName', $name);
            return redirect()->route('register');
        }

        $settings = Settings::first();
        $sponId = $settings->admin ?? null;
        Session::put('sponId', $sponId);
        if ($sponId) {
            $fallbackName = User::where('uuid', $sponId)->value('name');
            if (!empty($fallbackName)) {
                Session::put('sponsorName', $fallbackName);
            }
        }
        return redirect()->route('register');
    }
}
