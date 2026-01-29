<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\TidalController;
use App\Http\Controllers\BonusController;

Route::post('/get-location', [LocationController::class, 'getLocation']);
Route::post('/get-tidal/{city}', [TidalController::class, 'getTidal'])->middleware('rate_limit_tidal');

// Bonus nonce routes (para sistema de anúncios)
Route::post('/bonus/nonce', [BonusController::class, 'generateNonce']);
Route::post('/bonus/claim', [BonusController::class, 'claimNonce']);

