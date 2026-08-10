<?php

use App\Exceptions\NonReportableException;
use App\Models\EmailNotificationSettings;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\SendsEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Resend\Exceptions\ErrorException;
use Resend\Exceptions\TransporterException;

beforeEach(function () {
    // Mock the Team with members
    $this->team = Mockery::mock(Team::class);
    $this->team->id = 1;

    $user1 = new User(['email' => 'test@example.com']);
    $user2 = new User(['email' => 'admin@example.com']);
    $members = collect([$user1, $user2]);
    $this->team->shouldReceive('getAttribute')->with('members')->andReturn($members);
    Team::shouldReceive('find')->with(1)->andReturn($this->team);

    // Mock the notifiable (Team)
    $this->notifiable = Mockery::mock(SendsEmail::class);
    $this->notifiable->shouldReceive('getAttribute')->with('id')->andReturn(1);

    // Mock email settings with Resend enabled
    $this->settings = Mockery::mock(EmailNotificationSettings::class);
    $this->settings->resend_enabled = true;
    $this->settings->smtp_enabled = false;
    $this->settings->use_instance_email_settings = false;
    $this->settings->smtp_from_name = 'Test Sender';
    $this->settings->smtp_from_address = 'sender@example.com';
    $this->settings->resend_api_key = 'test_api_key';
    $this->settings->smtp_password = 'password';

    $this->notifiable->shouldReceive('getAttribute')->with('emailNotificationSettings')->andReturn($this->settings);
    $this->notifiable->emailNotificationSettings = $this->settings;
    $this->notifiable->shouldReceive('getRecipients')->andReturn(['test@example.com']);

    // Mock the notification
    $this->notification = Mockery::mock(Notification::class);
    $this->notification->shouldReceive('getAttribute')->with('isTransactionalEmail')->andReturn(false);
    $this->notification->shouldReceive('getAttribute')->with('emails')->andReturn(null);

    $mailMessage = Mockery::mock(MailMessage::class);
    $mailMessage->subject = 'Test Email';
    $mailMessage->shouldReceive('render')->andReturn('<html>Test</html>');

    $this->notification->shouldReceive('toMail')->andReturn($mailMessage);

    // Mock global functions
    $this->app->instance('send_internal_notification', function () {});
});

it('throws user-friendly error for invalid Resend API key (403)', function () {
    // Create mock ErrorException for invalid API key
    $resendError = Mockery::mock(ErrorException::class);
    $resendError->shouldReceive('getErrorCode')->andReturn(403);
    $resendError->shouldReceive('getErrorMessage')->andReturn('API key is invalid.');
    $resendError->shouldReceive('getCode')->andReturn(403);

    // Mock Resend client to throw the error
    $resendClient = Mockery::mock();
    $emailsService = Mockery::mock();
    $emailsService->shouldReceive('send')->andThrow($resendError);
    $resendClient->emails = $emailsService;

    Resend::shouldReceive('client')->andReturn($resendClient);

    $channel = new EmailChannel;

    expect(fn () => $channel->send($this->notifiable, $this->notification))
        ->toThrow(
            NonReportableException::class,
            'Invalid Resend API key. Please verify your API key in the Resend dashboard and update it in settings.'
        );
});

it('throws user-friendly error for restricted Resend API key (401)', function () {
    // Create mock ErrorException for restricted key
    $resendError = Mockery::mock(ErrorException::class);
    $resendError->shouldReceive('getErrorCode')->andReturn(401);
    $resendError->shouldReceive('getErrorMessage')->andReturn('This API key is restricted to only send emails.');
    $resendError->shouldReceive('getCode')->andReturn(401);

    // Mock Resend client to throw the error
    $resendClient = Mockery::mock();
    $emailsService = Mockery::mock();
    $emailsService->shouldReceive('send')->andThrow($resendError);
    $resendClient->emails = $emailsService;

    Resend::shouldReceive('client')->andReturn($resendClient);

    $channel = new EmailChannel;

    expect(fn () => $channel->send($this->notifiable, $this->notification))
        ->toThrow(
            NonReportableException::class,
            'Your Resend API key has restricted permissions. Please use an API key with Full Access permissions.'
        );
});

it('throws user-friendly error for rate limiting (429)', function () {
    // Create mock ErrorException for rate limit
    $resendError = Mockery::mock(ErrorException::class);
    $resendError->shouldReceive('getErrorCode')->andReturn(429);
    $resendError->shouldReceive('getErrorMessage')->andReturn('Too many requests.');
    $resendError->shouldReceive('getCode')->andReturn(429);

    // Mock Resend client to throw the error
    $resendClient = Mockery::mock();
    $emailsService = Mockery::mock();
    $emailsService->shouldReceive('send')->andThrow($resendError);
    $resendClient->emails = $emailsService;

    Resend::shouldReceive('client')->andReturn($resendClient);

    $channel = new EmailChannel;

    expect(fn () => $channel->send($this->notifiable, $this->notification))
        ->toThrow(Exception::class, 'Resend rate limit exceeded. Please try again in a few minutes.');
});

it('throws user-friendly error for validation errors (400)', function () {
    // Create mock ErrorException for validation error
    $resendError = Mockery::mock(ErrorException::class);
    $resendError->shouldReceive('getErrorCode')->andReturn(400);
    $resendError->shouldReceive('getErrorMessage')->andReturn('Invalid email format.');
    $resendError->shouldReceive('getCode')->andReturn(400);

    // Mock Resend client to throw the error
    $resendClient = Mockery::mock();
    $emailsService = Mockery::mock();
    $emailsService->shouldReceive('send')->andThrow($resendError);
    $resendClient->emails = $emailsService;

    Resend::shouldReceive('client')->andReturn($resendClient);

    $channel = new EmailChannel;

    expect(fn () => $channel->send($this->notifiable, $this->notification))
        ->toThrow(NonReportableException::class, 'Email validation failed: Invalid email format.');
});

it('throws user-friendly error for network/transport errors', function () {
    // Create mock TransporterException
    $transportError = Mockery::mock(TransporterException::class);
    $transportError->shouldReceive('getMessage')->andReturn('Network error');

    // Mock Resend client to throw the error
    $resendClient = Mockery::mock();
    $emailsService = Mockery::mock();
    $emailsService->shouldReceive('send')->andThrow($transportError);
    $resendClient->emails = $emailsService;

    Resend::shouldReceive('client')->andReturn($resendClient);

    $channel = new EmailChannel;

    expect(fn () => $channel->send($this->notifiable, $this->notification))
        ->toThrow(Exception::class, 'Unable to connect to Resend API. Please check your internet connection and try again.');
});

it('throws generic error with message for unknown error codes', function () {
    // Create mock ErrorException with unknown code
    $resendError = Mockery::mock(ErrorException::class);
    $resendError->shouldReceive('getErrorCode')->andReturn(500);
    $resendError->shouldReceive('getErrorMessage')->andReturn('Internal server error.');
    $resendError->shouldReceive('getCode')->andReturn(500);

    // Mock Resend client to throw the error
    $resendClient = Mockery::mock();
    $emailsService = Mockery::mock();
    $emailsService->shouldReceive('send')->andThrow($resendError);
    $resendClient->emails = $emailsService;

    Resend::shouldReceive('client')->andReturn($resendClient);

    $channel = new EmailChannel;

    expect(fn () => $channel->send($this->notifiable, $this->notification))
        ->toThrow(Exception::class, 'Failed to send email via Resend: Internal server error.');
});
