<?php

use App\Http\Controllers\Api\InboundEmailController;
use App\Http\Controllers\Api\PostmarkInboundController;
use Illuminate\Support\Facades\Route;

// Machine endpoints: no session, no CSRF; each one authenticates itself with a token.
Route::post('inbound-email', InboundEmailController::class)->middleware('throttle:120,1')->name('api.inbound-email');
Route::post('inbound-email/postmark', PostmarkInboundController::class)->middleware('throttle:120,1')->name('api.inbound-email.postmark');
