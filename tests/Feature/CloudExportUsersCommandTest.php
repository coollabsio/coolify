<?php

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('backups');
    config()->set('constants.coolify.self_hosted', false);
});

test('it exports subscribed and unsubscribed verified users to separate files without tags', function () {
    User::factory()->create([
        'id' => 0,
        'name' => 'Root User',
        'email' => 'root@example.com',
    ]);

    $subscribedUser = User::factory()->create([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
    ]);

    Subscription::create([
        'team_id' => $subscribedUser->teams()->firstOrFail()->id,
        'stripe_invoice_paid' => true,
        'stripe_subscription_id' => 'sub_active',
    ]);

    User::factory()->create([
        'name' => 'Grace',
        'email' => 'grace@example.com',
    ]);

    User::factory()->unverified()->create([
        'name' => 'Unverified User',
        'email' => 'unverified@example.com',
    ]);

    Storage::disk('backups')->put('cloud-users.csv', 'old export');

    $this->artisan('cloud:export-users')->assertSuccessful();

    Storage::disk('backups')->assertExists([
        'cloud-users-subscribed.csv',
        'cloud-users-unsubscribed.csv',
    ]);
    Storage::disk('backups')->assertMissing('cloud-users.csv');

    $readCsv = function (string $filename): array {
        $output = fopen(Storage::disk('backups')->path($filename), 'rb');
        $rows = [];

        while (($row = fgetcsv($output, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($output);

        return $rows;
    };

    $header = [
        'email',
        'first_name',
        'last_name',
        'lifetime_value_currency',
        'lifetime_value_amount',
        'utm_campaign',
        'utm_source',
        'utm_medium',
        'utm_content',
        'utm_term',
        'phone',
    ];

    expect($readCsv('cloud-users-subscribed.csv'))->toBe([
        $header,
        ['ada@example.com', 'Ada', 'Lovelace', '', '', '', '', '', '', '', ''],
    ])->and($readCsv('cloud-users-unsubscribed.csv'))->toBe([
        $header,
        ['grace@example.com', 'Grace', '', '', '', '', '', '', '', '', ''],
    ]);
});

test('it only runs on Coolify Cloud', function () {
    config()->set('constants.coolify.self_hosted', true);

    $this->artisan('cloud:export-users')
        ->expectsOutput('This command can only be run on Coolify Cloud.')
        ->assertFailed();

    Storage::disk('backups')->assertMissing([
        'cloud-users.csv',
        'cloud-users-subscribed.csv',
        'cloud-users-unsubscribed.csv',
    ]);
});
