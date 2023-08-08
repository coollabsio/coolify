<?php

use App\Models\S3Storage;
use Illuminate\Support\Str;

function set_s3_target(S3Storage $s3)
{
    $is_digital_ocean = false;
    if ($s3->endpoint) {
        $is_digital_ocean = Str::contains($s3->endpoint, 'digitaloceanspaces.com');
    }
    config()->set('filesystems.disks.custom-s3', [
        'driver' => 's3',
        'region' => $s3['region'],
        'key' => $s3['key'],
        'secret' => $s3['secret'],
        'bucket' => $s3['bucket'],
        'endpoint' => $s3['endpoint'],
        'use_path_style_endpoint' => true,
        'bucket_endpoint' => $is_digital_ocean,
        'aws_url' => $s3->awsUrl(),
    ]);
}
