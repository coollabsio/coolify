<?php

it('uses a dedicated notification event for restart limits', function () {
    $notification = file_get_contents(app_path('Notifications/Application/RestartLimitReached.php'));
    $telegramChannel = file_get_contents(app_path('Notifications/Channels/TelegramChannel.php'));
    $eventGrid = file_get_contents(resource_path('views/components/notification/event-grid.blade.php'));

    expect($notification)->toContain("getEnabledChannels('restart_limit_reached')")
        ->and($eventGrid)
        ->toContain("'Resources' => [")
        ->toContain("'key' => 'statusChange'")
        ->toContain("'helper' => 'Notify when a resource stops or Coolify automatically restarts it.'")
        ->toContain("'key' => 'restartLimitReached'")
        ->toContain("'label' => 'Restart limit reached'")
        ->and($telegramChannel)
        ->toContain('RestartLimitReached::class => $settings->telegram_notifications_restart_limit_reached_thread_id');
});

it('persists a restart limit notification preference for every channel', function (string $channel) {
    $studly = Str::studly($channel);
    $component = file_get_contents(app_path("Livewire/Notifications/{$studly}.php"));
    $model = file_get_contents(app_path('Models/'.$studly.'NotificationSettings.php'));
    $column = "restart_limit_reached_{$channel}_notifications";
    $property = "restartLimitReached{$studly}Notifications";

    expect($component)
        ->toContain("public bool \${$property} = true;")
        ->toContain("\$this->settings->{$column} = \$this->{$property};")
        ->toContain("\$this->{$property} = \$this->settings->{$column};")
        ->and($model)
        ->toContain("'{$column}'");
})->with(['email', 'discord', 'telegram', 'slack', 'pushover', 'webhook']);

it('enables restart limit notifications by default in every channel migration', function () {
    $migrations = collect(glob(database_path('migrations/*_add_restart_limit_reached_notifications_to_*')));

    expect($migrations)->toHaveCount(6);
    $migrations->each(fn (string $migration) => expect(file_get_contents($migration))->toContain('->default(true)'));
});

it('uses the inline validator facade for notification API updates', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/NotificationsController.php'));

    expect($controller)
        ->toContain('use Illuminate\\Support\\Facades\\Validator;')
        ->toContain("Validator::make(\$body, \$config['rules'])")
        ->not->toContain("customApiValidator(\$body, \$config['rules'])");
});
