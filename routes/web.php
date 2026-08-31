<?php
// Add to routes/web.php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('LandingPage.index');
})->name('home');

Route::get('/register', function () {
    return view('Auth.Register-Type');
})->name('register.type');

Route::get('/register/buyer', function () {
    return view('Auth.register-buyer');
})->name('register.buyer');

Route::get('/register/seller', function () {
    return view('Auth.register-seller');
})->name('register.seller');

Route::get('/register/logistics', function () {
    return view('Auth.register-logistics');
})->name('register.logistics');

Route::get('/shop', function () {
    return view('LandingPage.index');
})->name('shop.browse');

Route::get('/login', function () {
    return redirect()->back();
})->name('login');

Route::post('/login', function () {
    // TODO: implement real authentication
    return redirect()->route('home');
})->name('login.post');
