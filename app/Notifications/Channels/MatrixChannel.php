<?php

namespace App\Notifications\Channels;

use App\Jobs\SendMessageToMatrixJob;
use Illuminate\Notifications\Notification;

class MatrixChannel
{
    /**
     * Send the given notification.
     */
    public function send(SendsMatrix $notifiable, Notification $notification): void
    {
        $message = $notification->toMatrix();
        $matrixSettings = $notifiable->matrixNotificationSettings;

        if (! $matrixSettings || ! $matrixSettings->isEnabled() || ! $matrixSettings->matrix_homeserver_url || ! $matrixSettings->matrix_room_id || ! $matrixSettings->matrix_access_token) {
            return;
        }

        SendMessageToMatrixJob::dispatch(
            $message,
            $matrixSettings->matrix_homeserver_url,
            $matrixSettings->matrix_room_id,
            $matrixSettings->matrix_access_token
        );
    }
}