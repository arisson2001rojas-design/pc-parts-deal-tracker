<?php

use App\Enums\ApiAbility;
use App\Http\Controllers\Api\MetaExtractionController;
use App\Http\Controllers\BrowserDiscoveryController;
use App\Http\Controllers\BrowserPriceCaptureController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user()->only(['id', 'name', 'email']);
})->middleware(['auth:sanctum', 'ability:'.ApiAbility::UserDetail->value])->name('api.user');

Route::post('/meta-extraction', MetaExtractionController::class)
    ->middleware(['auth:sanctum', 'ability:'.ApiAbility::MetaExtractionExtract->value])
    ->name('api.meta-extraction');

Route::post('/browser-capture/{offer}', BrowserPriceCaptureController::class)
    ->middleware(['signed:relative', 'throttle:30,1'])
    ->name('api.browser-capture');

Route::post('/browser-discoveries', BrowserDiscoveryController::class)
    ->middleware('throttle:60,1')
    ->name('api.browser-discoveries');
