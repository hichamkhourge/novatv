<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// All IPTV/Xtream API routes have been moved to routes/xtream.php
