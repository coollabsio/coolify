<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
#[IsIdempotent]
class ManageNotifications extends RestActionTool
{
    protected string $name = 'manage_notifications';

    protected string $description = 'Read or change the notification settings of the authenticated team per channel (resource: email, discord, slack, telegram, pushover, webhook). Webhook URLs, tokens, and passwords are never returned. Requires read ability for get, write ability for update. Same validation and permissions as the REST API.';

    private const CHANNEL_PATHS = [
        'email' => 'notifications/email',
        'discord' => 'notifications/discord',
        'slack' => 'notifications/slack',
        'telegram' => 'notifications/telegram',
        'pushover' => 'notifications/pushover',
        'webhook' => 'notifications/webhook',
    ];

    protected function actions(): array
    {
        return [
            'get' => ['method' => 'GET', 'paths' => self::CHANNEL_PATHS, 'ability' => 'read', 'description' => 'settings of one channel'],
            'update' => ['method' => 'PATCH', 'paths' => self::CHANNEL_PATHS, 'ability' => 'write', 'description' => 'change settings of one channel (data: fields of that channel, for example discord_enabled, discord_webhook_url, deployment_failure_discord_notifications)'],
        ];
    }

    protected function pathParams(): array
    {
        return [];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API for the channel (for example smtp_*, resend_*, *_enabled, *_webhook_url, telegram_token, telegram_chat_id, pushover_user_key, pushover_api_token, and the per-event *_notifications flags).';
    }
}
