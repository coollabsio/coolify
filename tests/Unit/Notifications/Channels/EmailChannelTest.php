<?php

use App\Exceptions\NonReportableException;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Channels\EmailChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->resendStub = null;
    $this->team = Team::factory()->create();
    $user = User::factory()->create(['email' => 'test@example.com']);
    $this->team->members()->attach($user->id, ['role' => 'owner']);

    $this->team->emailNotificationSettings->update([
        'resend_enabled' => true,
        'smtp_enabled' => false,
        'use_instance_email_settings' => false,
        'smtp_from_name' => 'Test Sender',
        'smtp_from_address' => 'sender@example.com',
        'resend_api_key' => 'test_api_key',
    ]);
    $this->team->refresh();

    $this->notification = new class extends Notification
    {
        public function toMail(object $notifiable): MailMessage
        {
            return (new MailMessage)->subject('Test Email')->line('Test');
        }
    };
});

afterEach(function () {
    $this->resendStub?->stop();
    putenv('RESEND_BASE_URL');
});

/**
 * Point the real Resend client at a local HTTP stub that answers every request
 * with the given Resend error, so the whole Resend SDK error path is exercised.
 */
function fakeResendError(int $code, string $message): Process
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) parse_url('tcp://'.stream_socket_get_name($socket, false), PHP_URL_PORT);
    fclose($socket);

    $router = sys_get_temp_dir()."/coolify-resend-stub-{$port}.php";
    file_put_contents($router, <<<'PHP'
        <?php
        $code = (int) getenv('RESEND_STUB_CODE');
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['error' => ['message' => getenv('RESEND_STUB_MESSAGE'), 'type' => 'error', 'code' => $code]]);
        PHP);

    $server = new Process(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
        env: ['RESEND_STUB_CODE' => (string) $code, 'RESEND_STUB_MESSAGE' => $message],
    );
    $server->start();

    $deadline = microtime(true) + 5;
    while (! @fsockopen('127.0.0.1', $port) && microtime(true) < $deadline) {
        usleep(20_000);
    }

    putenv("RESEND_BASE_URL=http://127.0.0.1:{$port}");

    return $server;
}

it('throws user-friendly error for invalid Resend API key (403)', function () {
    $this->resendStub = fakeResendError(403, 'API key is invalid.');

    expect(fn () => (new EmailChannel)->send($this->team, $this->notification))
        ->toThrow(
            NonReportableException::class,
            'Invalid Resend API key. Please verify your API key in the Resend dashboard and update it in settings.'
        );
});

it('throws user-friendly error for restricted Resend API key (401)', function () {
    $this->resendStub = fakeResendError(401, 'This API key is restricted to only send emails.');

    expect(fn () => (new EmailChannel)->send($this->team, $this->notification))
        ->toThrow(
            NonReportableException::class,
            'Your Resend API key has restricted permissions. Please use an API key with Full Access permissions.'
        );
});

it('throws user-friendly error for rate limiting (429)', function () {
    $this->resendStub = fakeResendError(429, 'Too many requests.');

    expect(fn () => (new EmailChannel)->send($this->team, $this->notification))
        ->toThrow(Exception::class, 'Resend rate limit exceeded. Please try again in a few minutes.');
});

it('throws user-friendly error for validation errors (400)', function () {
    $this->resendStub = fakeResendError(400, 'Invalid email format.');

    expect(fn () => (new EmailChannel)->send($this->team, $this->notification))
        ->toThrow(NonReportableException::class, 'Email validation failed: Invalid email format.');
});

it('throws user-friendly error for network/transport errors', function () {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $closedPort = (int) parse_url('tcp://'.stream_socket_get_name($socket, false), PHP_URL_PORT);
    fclose($socket);
    putenv("RESEND_BASE_URL=http://127.0.0.1:{$closedPort}");

    expect(fn () => (new EmailChannel)->send($this->team, $this->notification))
        ->toThrow(Exception::class, 'Unable to connect to Resend API. Please check your internet connection and try again.');
});

it('throws generic error with message for unknown error codes', function () {
    $this->resendStub = fakeResendError(500, 'Internal server error.');

    expect(fn () => (new EmailChannel)->send($this->team, $this->notification))
        ->toThrow(Exception::class, 'Failed to send email via Resend: Internal server error.');
});
