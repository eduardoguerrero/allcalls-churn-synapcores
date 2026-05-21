<?php

use App\Http\Controllers\Api\MemberController;
use Illuminate\Support\Facades\Route;

Route::get('/members/at-risk', [MemberController::class, 'atRisk']);
Route::post('/members/{member}/offer', [MemberController::class, 'sendOffer']);
