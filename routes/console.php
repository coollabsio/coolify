<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Mailer\Mailer;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {

    $smtp = [
        "transport" => "smtp",
        "host" => "mailpit",
        "port" => 1025,
        "encryption" => 'tls',
        "username" => null,
        "password" => null,
        "timeout" => null,
        "local_domain" => null,
    ];
    config()->set('mail.mailers.smtp', $smtp);

//    \Illuminate\Support\Facades\Mail::mailer('smtp')
//        ->to('ask@me.com')
//        ->send(new \App\Mail\TestMail);

    \Illuminate\Support\Facades\Notification::send(
        \App\Models\User::find(1),
        new \App\Notifications\TestMessage
    );

})->purpose('Display an inspiring quote');
