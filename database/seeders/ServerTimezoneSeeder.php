<?php

namespace Database\Seeders;

use App\Models\Server;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;


class ServerTimezoneSeeder extends Seeder
{
    public function run(): void
    {
        $defaultTimezone = config('app.timezone');

        Server::whereHas('settings', function ($query) {
            $query->whereNull('server_timezone')->orWhere('server_timezone', '');
        })->each(function ($server) use ($defaultTimezone) {
            DB::transaction(function () use ($server, $defaultTimezone) {
                $this->updateServerTimezone($server, $defaultTimezone);
            });
        });
    }

    private function updateServerTimezone($server, $desired_timezone)
    {
        $commands = [
            "if command -v timedatectl > /dev/null 2>&1 && pidof systemd > /dev/null; then",
            "    timedatectl set-timezone " . escapeshellarg($desired_timezone),
            "elif [ -f /etc/timezone ]; then",
            "    echo " . escapeshellarg($desired_timezone) . " > /etc/timezone",
            "    rm -f /etc/localtime",
            "    ln -sf /usr/share/zoneinfo/" . escapeshellarg($desired_timezone) . " /etc/localtime",
            "elif [ -f /etc/localtime ]; then",
            "    rm -f /etc/localtime",
            "    ln -sf /usr/share/zoneinfo/" . escapeshellarg($desired_timezone) . " /etc/localtime",
            "fi",
            "if command -v dpkg-reconfigure > /dev/null 2>&1; then",
            "    dpkg-reconfigure -f noninteractive tzdata",
            "elif command -v tzdata-update > /dev/null 2>&1; then",
            "    tzdata-update",
            "elif [ -f /etc/sysconfig/clock ]; then",
            "    sed -i 's/^ZONE=.*/ZONE=\"" . $desired_timezone . "\"/' /etc/sysconfig/clock",
            "    source /etc/sysconfig/clock",
            "fi",
            "if command -v systemctl > /dev/null 2>&1 && pidof systemd > /dev/null; then",
            "    systemctl try-restart systemd-timesyncd.service || true",
            "elif command -v service > /dev/null 2>&1; then",
            "    service ntpd restart || service ntp restart || true",
            "fi"
        ];

        instant_remote_process($commands, $server);

        $server->settings->server_timezone = $desired_timezone;
        $server->settings->save();
    }
}
