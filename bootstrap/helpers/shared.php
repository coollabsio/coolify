<?php

namespace App\Bootstrap\Helpers;

use App\Models\Application;
use App\Models\Service;
use App\Models\ServiceDatabase;
use Illuminate\Support\Arr;

class Shared
{
    public static function parseDockerComposeFile($dockerComposeFile)
    {
        $services = Arr::get($dockerComposeFile, 'services', []);

        foreach ($services as $serviceName => $service) {
            $image = Arr::get($service, 'image', '');

            if (isDatabaseImage($image, $service)) {
                $isDatabase = true;
            } else {
                $isDatabase = false;
            }

            data_set($service, 'is_database', $isDatabase);

            if ($isDatabase) {
                $serviceDatabase = ServiceDatabase::firstOrCreate([
                    'service_id' => $service['id'],
                ], [
                    'name' => $serviceName,
                    'type' => Arr::get($service, 'type', 'unknown'),
                    'image' => $image,
                ]);

                $service['service_database_id'] = $serviceDatabase->id;
            }

            if ($service['type'] === 'application') {
                $application = Application::firstOrCreate([
                    'service_id' => $service['id'],
                ], [
                    'name' => $serviceName,
                    'type' => Arr::get($service, 'type', 'unknown'),
                    'image' => $image,
                ]);

                $service['application_id'] = $application->id;
            }
        }

        return $services;
    }

    public static function isDatabaseImage($image, $service)
    {
        // Add your database detection logic here
        // For example, check if the image name contains common database keywords
        return strpos($image, 'mysql') !== false || strpos($image, 'postgres') !== false || strpos($image, 'mongodb') !== false;
    }
}
