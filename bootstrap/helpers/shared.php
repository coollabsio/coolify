<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ProxyTypes;
use App\Jobs\ServerFilesFromServerJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Process\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Laravel\Horizon\Contracts\JobRepository;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;
use Poliander\Cron\CronExpression;
use PurplePixie\PhpDns\DNSQuery;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;
use Visus\Cuid2\Cuid2;

[FILE_CONTENT_TOO_LARGE_FOR_JSON]

            // Decide if the service is a database
            $image = data_get_str($service, 'image');
            $isDatabase = isDatabaseImage($image, $service);
            data_set($service, 'is_database', $isDatabase);

            // Create/update ServiceDatabase or ServiceApplication records for database backup support
            if ($isDatabase) {
                if ($isNew) {
                    $savedService = ServiceDatabase::create([
                        'name' => $serviceName,
                        'image' => $image,
                        'service_id' => $resource->id,
                    ]);
                } else {
                    $savedService = ServiceDatabase::where([
                        'name' => $serviceName,
                        'service_id' => $resource->id,
                    ])->first();
                    if (is_null($savedService)) {
                        $savedService = ServiceDatabase::create([
                            'name' => $serviceName,
                            'image' => $image,
                            'service_id' => $resource->id,
                        ]);
                    }
                }
                // Update image if it changed
                if ($savedService->image !== $image) {
                    $savedService->image = $image;
                    $savedService->save();
                }
            } else {
                if ($isNew) {
                    ServiceApplication::create([
                        'name' => $serviceName,
                        'image' => $image,
                        'service_id' => $resource->id,
                    ]);
                } else {
                    $serviceApp = ServiceApplication::where([
                        'name' => $serviceName,
                        'service_id' => $resource->id,
                    ])->first();
                    if (is_null($serviceApp)) {
                        ServiceApplication::create([
                            'name' => $serviceName,
                            'image' => $image,
                            'service_id' => $resource->id,
                        ]);
                    } else {
                        // Update image if it changed
                        if ($serviceApp->image !== $image) {
                            $serviceApp->image = $image;
                            $serviceApp->save();
                        }
                    }
                }
            }

            // Collect/create/update networks