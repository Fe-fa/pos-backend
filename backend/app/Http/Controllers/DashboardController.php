<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {
        // Example: pass the logged-in user to the view
        $user = Auth::user();

        return view('dashboard', [
            'user' => $user,
        ]);
    }
}
