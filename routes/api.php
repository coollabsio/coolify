<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::get('/health', function () {
    return 'OK';
});

Route::middleware(['throttle:5'])->group(function () {
    Route::get('/unsubscribe/{token}', function() {
        try {
            $token = request()->token;
            $email = decrypt($token);
            if (!User::whereEmail($email)->exists()) {
                return redirect('/');
            }
            if (User::whereEmail($email)->first()->marketing_emails === false) {
                return 'You have already unsubscribed from marketing emails.';
            }
            User::whereEmail($email)->update(['marketing_emails' => false]);
            return 'You have been unsubscribed from marketing emails.';
        } catch (\Throwable $e) {
            return 'Something went wrong. Please try again or contact support.';
        }

    })->name('unsubscribe.marketing.emails');
});
