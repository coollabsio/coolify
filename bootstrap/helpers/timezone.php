<?php

function getServerTimezone($server = null)
{
    if (! $server) {
        return 'UTC';
    }

    return data_get($server, 'settings.server_timezone', 'UTC');
}

function formatDateInServerTimezone($date, $server = null)
{
    $serverTimezone = getServerTimezone($server);
    $dateObj = new \DateTime($date);
    try {
        $dateObj->setTimezone(new \DateTimeZone($serverTimezone));
    } catch (\Exception) {
        $dateObj->setTimezone(new \DateTimeZone('UTC'));
    }

    return $dateObj->format('Y-m-d H:i:s T');
}

function calculateDuration($startDate, $endDate = null)
{
    if (! $endDate) {
        return null;
    }

    $start = new \DateTime($startDate);
    $end = new \DateTime($endDate);
    $interval = $start->diff($end);

    if ($interval->days > 0) {
        return $interval->format('%dd %Hh %Im %Ss');
    } elseif ($interval->h > 0) {
        return $interval->format('%Hh %Im %Ss');
    } else {
        return $interval->format('%Im %Ss');
    }
}
