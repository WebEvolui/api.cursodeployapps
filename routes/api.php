<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\TidalController;

Route::post('/get-location', [LocationController::class, 'getLocation']);
Route::post('/get-tidal/{city}', [TidalController::class, 'getTidal'])->middleware('rate_limit_tidal');
