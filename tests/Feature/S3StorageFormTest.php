<?php

use App\Livewire\Storage\Form;
use App\Models\S3Storage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

it('tests the S3 connection with the values currently entered in the form', function () {
    if (! defined('CURLOPT_RESOLVE')) {
        define('CURLOPT_RESOLVE', 10203);
    }

    $disk = Mockery::mock();
    $disk->expects('files')->once()->andReturn([]);
    $testedConfig = null;

    Storage::expects('build')
        ->once()
        ->with(Mockery::on(function (array $config) use (&$testedConfig) {
            $testedConfig = $config;

            return true;
        }))
        ->andReturn($disk);

    $storage = new class extends S3Storage
    {
        public function save(array $options = []): bool
        {
            return true;
        }
    };
    $storage->setRawAttributes([
        'name' => 'Saved storage',
        'description' => 'Saved description',
        'endpoint' => 'https://nyc3.digitaloceanspaces.com',
        'bucket' => 'old-bucket',
        'region' => 'us-east-1',
        'key' => null,
        'secret' => null,
        'is_usable' => false,
        'unusable_email_sent' => true,
    ]);
    $form = new class extends Form
    {
        public function authorize($ability, $arguments = []): void {}

        public function dispatch($event, ...$params): void {}
    };
    $form->storage = $storage;
    $form->name = 'Unsaved storage';
    $form->description = 'Unsaved description';
    $form->endpoint = 'https://s3.amazonaws.com';
    $form->bucket = 'new-bucket';
    $form->region = 'eu-central-1';
    $form->key = 'new-key';
    $form->secret = 'new-secret';
    $form->isUsable = false;

    $form->testConnection();

    expect($form->endpoint)->toBe('https://s3.amazonaws.com')
        ->and($storage->endpoint)->toBe('https://nyc3.digitaloceanspaces.com')
        ->and($testedConfig)->toMatchArray([
            'endpoint' => 'https://s3.amazonaws.com',
            'bucket' => 'new-bucket',
            'region' => 'eu-central-1',
            'key' => 'new-key',
            'secret' => 'new-secret',
        ])
        ->and($form->isUsable)->toBeTrue();
});
