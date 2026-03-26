<?php

use App\Jobs\SyncMailcheepContactJob;
use App\Models\Team;
use App\Models\User;
use App\Services\MailcheepService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('subscription.mailcheep_api_key', 'test-api-key');
    config()->set('subscription.mailcheep_list_subscribed', 'list-subscribed-id');
    config()->set('subscription.mailcheep_list_churned', 'list-churned-id');
});

describe('MailcheepService', function () {
    it('finds a contact by email', function () {
        Http::fake([
            'api.mailcheep.cloud/v1/contacts*' => Http::response([
                'data' => [
                    ['id' => 'contact-1', 'email' => 'test@example.com', 'name' => 'Test User'],
                ],
            ]),
        ]);

        $service = new MailcheepService;
        $result = $service->findContactByEmail('test@example.com');

        expect($result)->not->toBeNull()
            ->and($result['email'])->toBe('test@example.com');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/contacts') && $request->data()['search'] === 'test@example.com';
        });
    });

    it('returns null when contact is not found', function () {
        Http::fake([
            'api.mailcheep.cloud/v1/contacts*' => Http::response(['data' => []]),
        ]);

        $service = new MailcheepService;
        $result = $service->findContactByEmail('missing@example.com');

        expect($result)->toBeNull();
    });

    it('creates a contact', function () {
        Http::fake([
            'api.mailcheep.cloud/v1/contacts' => Http::response([
                'data' => ['id' => 'contact-new', 'email' => 'new@example.com'],
            ]),
        ]);

        $service = new MailcheepService;
        $result = $service->createContact('new@example.com', 'New User', 'list-id', ['team_id' => '1']);

        expect($result)->not->toBeNull()
            ->and($result['email'])->toBe('new@example.com');

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request['email'] === 'new@example.com'
                && $request['list_id'] === 'list-id';
        });
    });

    it('updates a contact', function () {
        Http::fake([
            'api.mailcheep.cloud/v1/contacts/contact-1' => Http::response([
                'data' => ['id' => 'contact-1', 'list_id' => 'new-list'],
            ]),
        ]);

        $service = new MailcheepService;
        $result = $service->updateContact('contact-1', ['list_id' => 'new-list']);

        expect($result)->not->toBeNull();

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT' && $request['list_id'] === 'new-list';
        });
    });

    it('creates or updates a contact when existing', function () {
        Http::fake([
            'api.mailcheep.cloud/v1/contacts?search=*' => Http::response([
                'data' => [['id' => 'contact-1', 'email' => 'existing@example.com']],
            ]),
            'api.mailcheep.cloud/v1/contacts/contact-1' => Http::response([
                'data' => ['id' => 'contact-1', 'name' => 'Updated'],
            ]),
        ]);

        $service = new MailcheepService;
        $result = $service->createOrUpdateContact('existing@example.com', 'Updated', 'list-id');

        expect($result)->not->toBeNull();
    });

    it('returns null on API failure without throwing', function () {
        Http::fake([
            'api.mailcheep.cloud/v1/contacts*' => Http::response('Server Error', 500),
        ]);

        $service = new MailcheepService;
        $result = $service->findContactByEmail('fail@example.com');

        expect($result)->toBeNull();
    });

    it('sends X-API-Key header', function () {
        Http::fake([
            'api.mailcheep.cloud/v1/contacts*' => Http::response(['data' => []]),
        ]);

        $service = new MailcheepService;
        $service->findContactByEmail('test@example.com');

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-API-Key', 'test-api-key');
        });
    });
});

describe('SyncMailcheepContactJob', function () {
    it('calls create_or_update on the service', function () {
        Http::fake([
            'api.mailcheep.cloud/v1/contacts?search=*' => Http::response(['data' => []]),
            'api.mailcheep.cloud/v1/contacts' => Http::response([
                'data' => ['id' => 'new', 'email' => 'job@example.com'],
            ]),
        ]);

        $job = new SyncMailcheepContactJob(
            action: 'create_or_update',
            email: 'job@example.com',
            name: 'Job User',
            customFields: ['team_id' => '1'],
        );

        $job->handle(app(MailcheepService::class));

        Http::assertSent(function ($request) {
            return $request->method() === 'POST' && $request['email'] === 'job@example.com';
        });
    });

    it('calls add_to_churned on the service', function () {
        Http::fake([
            'api.mailcheep.cloud/v1/contacts?search=*' => Http::response([
                'data' => [['id' => 'contact-1', 'email' => 'churn@example.com']],
            ]),
            'api.mailcheep.cloud/v1/contacts/contact-1' => Http::response([
                'data' => ['id' => 'contact-1'],
            ]),
        ]);

        $job = new SyncMailcheepContactJob(
            action: 'add_to_churned',
            email: 'churn@example.com',
            name: 'Churn User',
        );

        $job->handle(app(MailcheepService::class));

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT' && $request['list_id'] === 'list-churned-id';
        });
    });

    it('dispatchForTeam skips when no API key configured', function () {
        config()->set('subscription.mailcheep_api_key', null);
        Bus::fake();

        $team = Team::factory()->create();

        SyncMailcheepContactJob::dispatchForTeam($team, 'create_or_update');

        Bus::assertNothingDispatched();
    });

    it('dispatchForTeam dispatches job for team owner', function () {
        Bus::fake();

        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($user->id, ['role' => 'owner']);
        $team->load('members');

        SyncMailcheepContactJob::dispatchForTeam($team, 'create_or_update', ['team_id' => (string) $team->id]);

        Bus::assertDispatched(SyncMailcheepContactJob::class, function ($job) use ($user) {
            return $job->action === 'create_or_update'
                && $job->email === $user->email
                && $job->name === $user->name;
        });
    });
});

describe('SyncMailcheep command', function () {
    it('fails when not on cloud', function () {
        // isCloud() returns false by default in tests
        $this->artisan('sync:mailcheep')
            ->assertFailed();
    });
});
