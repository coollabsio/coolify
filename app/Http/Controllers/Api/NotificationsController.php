<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiscordNotificationSettings;
use App\Models\EmailNotificationSettings;
use App\Models\PushoverNotificationSettings;
use App\Models\SlackNotificationSettings;
use App\Models\Team;
use App\Models\TelegramNotificationSettings;
use App\Models\WebhookNotificationSettings;
use App\Rules\SafeWebhookUrl;
use App\Rules\ValidHostname;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class NotificationsController extends Controller
{
    /**
     * @return array{model: class-string<Model>, rules: array<string, mixed>}
     */
    private function channelConfig(string $channel): array
    {
        return match ($channel) {
            'email' => [
                'model' => EmailNotificationSettings::class,
                'rules' => [
                    'smtp_enabled' => 'sometimes|boolean',
                    'smtp_from_address' => 'sometimes|nullable|email',
                    'smtp_from_name' => 'sometimes|nullable|string|max:255',
                    'smtp_recipients' => 'sometimes|nullable|string|max:1000',
                    'smtp_host' => 'sometimes|nullable|string|max:255',
                    'smtp_port' => 'sometimes|nullable|integer|min:1|max:65535',
                    'smtp_encryption' => 'sometimes|nullable|string|in:starttls,tls,none',
                    'smtp_username' => 'sometimes|nullable|string|max:255',
                    'smtp_password' => 'sometimes|nullable|string|max:255',
                    'smtp_timeout' => 'sometimes|nullable|integer|min:0',
                    'smtp_ehlo_domain' => ['sometimes', 'nullable', 'string', 'max:255', new ValidHostname],
                    'resend_enabled' => 'sometimes|boolean',
                    'resend_api_key' => 'sometimes|nullable|string|max:255',
                    'use_instance_email_settings' => 'sometimes|boolean',
                    'deployment_success_email_notifications' => 'sometimes|boolean',
                    'deployment_failure_email_notifications' => 'sometimes|boolean',
                    'status_change_email_notifications' => 'sometimes|boolean',
                    'restart_limit_reached_email_notifications' => 'sometimes|boolean',
                    'backup_success_email_notifications' => 'sometimes|boolean',
                    'backup_failure_email_notifications' => 'sometimes|boolean',
                    'scheduled_task_success_email_notifications' => 'sometimes|boolean',
                    'scheduled_task_failure_email_notifications' => 'sometimes|boolean',
                    'docker_cleanup_success_email_notifications' => 'sometimes|boolean',
                    'docker_cleanup_failure_email_notifications' => 'sometimes|boolean',
                    'server_disk_usage_email_notifications' => 'sometimes|boolean',
                    'server_reachable_email_notifications' => 'sometimes|boolean',
                    'server_unreachable_email_notifications' => 'sometimes|boolean',
                    'server_patch_email_notifications' => 'sometimes|boolean',
                    'traefik_outdated_email_notifications' => 'sometimes|boolean',
                ],
            ],
            'discord' => [
                'model' => DiscordNotificationSettings::class,
                'rules' => [
                    'discord_enabled' => 'sometimes|boolean',
                    'discord_webhook_url' => ['sometimes', 'nullable', 'string', new SafeWebhookUrl],
                    'deployment_success_discord_notifications' => 'sometimes|boolean',
                    'deployment_failure_discord_notifications' => 'sometimes|boolean',
                    'status_change_discord_notifications' => 'sometimes|boolean',
                    'restart_limit_reached_discord_notifications' => 'sometimes|boolean',
                    'backup_success_discord_notifications' => 'sometimes|boolean',
                    'backup_failure_discord_notifications' => 'sometimes|boolean',
                    'scheduled_task_success_discord_notifications' => 'sometimes|boolean',
                    'scheduled_task_failure_discord_notifications' => 'sometimes|boolean',
                    'docker_cleanup_success_discord_notifications' => 'sometimes|boolean',
                    'docker_cleanup_failure_discord_notifications' => 'sometimes|boolean',
                    'server_disk_usage_discord_notifications' => 'sometimes|boolean',
                    'server_reachable_discord_notifications' => 'sometimes|boolean',
                    'server_unreachable_discord_notifications' => 'sometimes|boolean',
                    'server_patch_discord_notifications' => 'sometimes|boolean',
                    'traefik_outdated_discord_notifications' => 'sometimes|boolean',
                    'discord_ping_enabled' => 'sometimes|boolean',
                ],
            ],
            'slack' => [
                'model' => SlackNotificationSettings::class,
                'rules' => [
                    'slack_enabled' => 'sometimes|boolean',
                    'slack_webhook_url' => ['sometimes', 'nullable', 'string', new SafeWebhookUrl],
                    'deployment_success_slack_notifications' => 'sometimes|boolean',
                    'deployment_failure_slack_notifications' => 'sometimes|boolean',
                    'status_change_slack_notifications' => 'sometimes|boolean',
                    'restart_limit_reached_slack_notifications' => 'sometimes|boolean',
                    'backup_success_slack_notifications' => 'sometimes|boolean',
                    'backup_failure_slack_notifications' => 'sometimes|boolean',
                    'scheduled_task_success_slack_notifications' => 'sometimes|boolean',
                    'scheduled_task_failure_slack_notifications' => 'sometimes|boolean',
                    'docker_cleanup_success_slack_notifications' => 'sometimes|boolean',
                    'docker_cleanup_failure_slack_notifications' => 'sometimes|boolean',
                    'server_disk_usage_slack_notifications' => 'sometimes|boolean',
                    'server_reachable_slack_notifications' => 'sometimes|boolean',
                    'server_unreachable_slack_notifications' => 'sometimes|boolean',
                    'server_patch_slack_notifications' => 'sometimes|boolean',
                    'traefik_outdated_slack_notifications' => 'sometimes|boolean',
                ],
            ],
            'telegram' => [
                'model' => TelegramNotificationSettings::class,
                'rules' => [
                    'telegram_enabled' => 'sometimes|boolean',
                    'telegram_token' => 'sometimes|nullable|string|max:255',
                    'telegram_chat_id' => 'sometimes|nullable|string|max:255',
                    'deployment_success_telegram_notifications' => 'sometimes|boolean',
                    'deployment_failure_telegram_notifications' => 'sometimes|boolean',
                    'status_change_telegram_notifications' => 'sometimes|boolean',
                    'restart_limit_reached_telegram_notifications' => 'sometimes|boolean',
                    'backup_success_telegram_notifications' => 'sometimes|boolean',
                    'backup_failure_telegram_notifications' => 'sometimes|boolean',
                    'scheduled_task_success_telegram_notifications' => 'sometimes|boolean',
                    'scheduled_task_failure_telegram_notifications' => 'sometimes|boolean',
                    'docker_cleanup_success_telegram_notifications' => 'sometimes|boolean',
                    'docker_cleanup_failure_telegram_notifications' => 'sometimes|boolean',
                    'server_disk_usage_telegram_notifications' => 'sometimes|boolean',
                    'server_reachable_telegram_notifications' => 'sometimes|boolean',
                    'server_unreachable_telegram_notifications' => 'sometimes|boolean',
                    'server_patch_telegram_notifications' => 'sometimes|boolean',
                    'traefik_outdated_telegram_notifications' => 'sometimes|boolean',
                    'telegram_notifications_deployment_success_thread_id' => 'sometimes|nullable|string|max:255',
                    'telegram_notifications_deployment_failure_thread_id' => 'sometimes|nullable|string|max:255',
                    'telegram_notifications_status_change_thread_id' => 'sometimes|nullable|string|max:255',
                    'telegram_notifications_restart_limit_reached_thread_id' => 'sometimes|nullable|string|max:255',
                    'telegram_notifications_backup_success_thread_id' => 'sometimes|nullable|string|max:255',
                    'telegram_notifications_backup_failure_thread_id' => 'sometimes|nullable|string|max:255',
                    'telegram_notifications_scheduled_task_success_thread_id' => 'sometimes|nullable|string|max:255',
                    'telegram_notifications_scheduled_task_failure_thread_id' => 'sometimes|nullable|string|max:255',
                    'telegram_notifications_docker_cleanup_success_thread_id' => 'sometimes|nullable|string|max:255',
                    'telegram_notifications_docker_cleanup_failure_thread_id' => 'sometimes|nullable|string|max:255',
                    'telegram_notifications_server_disk_usage_thread_id' => 'sometimes|nullable|string|max:255',
                    'telegram_notifications_server_reachable_thread_id' => 'sometimes|nullable|string|max:255',
                    'telegram_notifications_server_unreachable_thread_id' => 'sometimes|nullable|string|max:255',
                    'telegram_notifications_server_patch_thread_id' => 'sometimes|nullable|string|max:255',
                    'telegram_notifications_traefik_outdated_thread_id' => 'sometimes|nullable|string|max:255',
                ],
            ],
            'pushover' => [
                'model' => PushoverNotificationSettings::class,
                'rules' => [
                    'pushover_enabled' => 'sometimes|boolean',
                    'pushover_user_key' => 'sometimes|nullable|string|max:255',
                    'pushover_api_token' => 'sometimes|nullable|string|max:255',
                    'deployment_success_pushover_notifications' => 'sometimes|boolean',
                    'deployment_failure_pushover_notifications' => 'sometimes|boolean',
                    'status_change_pushover_notifications' => 'sometimes|boolean',
                    'restart_limit_reached_pushover_notifications' => 'sometimes|boolean',
                    'backup_success_pushover_notifications' => 'sometimes|boolean',
                    'backup_failure_pushover_notifications' => 'sometimes|boolean',
                    'scheduled_task_success_pushover_notifications' => 'sometimes|boolean',
                    'scheduled_task_failure_pushover_notifications' => 'sometimes|boolean',
                    'docker_cleanup_success_pushover_notifications' => 'sometimes|boolean',
                    'docker_cleanup_failure_pushover_notifications' => 'sometimes|boolean',
                    'server_disk_usage_pushover_notifications' => 'sometimes|boolean',
                    'server_reachable_pushover_notifications' => 'sometimes|boolean',
                    'server_unreachable_pushover_notifications' => 'sometimes|boolean',
                    'server_patch_pushover_notifications' => 'sometimes|boolean',
                    'traefik_outdated_pushover_notifications' => 'sometimes|boolean',
                ],
            ],
            'webhook' => [
                'model' => WebhookNotificationSettings::class,
                'rules' => [
                    'webhook_enabled' => 'sometimes|boolean',
                    'webhook_url' => ['sometimes', 'nullable', 'string', new SafeWebhookUrl],
                    'deployment_success_webhook_notifications' => 'sometimes|boolean',
                    'deployment_failure_webhook_notifications' => 'sometimes|boolean',
                    'status_change_webhook_notifications' => 'sometimes|boolean',
                    'restart_limit_reached_webhook_notifications' => 'sometimes|boolean',
                    'backup_success_webhook_notifications' => 'sometimes|boolean',
                    'backup_failure_webhook_notifications' => 'sometimes|boolean',
                    'scheduled_task_success_webhook_notifications' => 'sometimes|boolean',
                    'scheduled_task_failure_webhook_notifications' => 'sometimes|boolean',
                    'docker_cleanup_success_webhook_notifications' => 'sometimes|boolean',
                    'docker_cleanup_failure_webhook_notifications' => 'sometimes|boolean',
                    'server_disk_usage_webhook_notifications' => 'sometimes|boolean',
                    'server_reachable_webhook_notifications' => 'sometimes|boolean',
                    'server_unreachable_webhook_notifications' => 'sometimes|boolean',
                    'server_patch_webhook_notifications' => 'sometimes|boolean',
                    'traefik_outdated_webhook_notifications' => 'sometimes|boolean',
                ],
            ],
            default => throw new \InvalidArgumentException("Unknown notification channel [{$channel}]."),
        };
    }

    /**
     * @return list<string>
     */
    private function allowedFields(string $channel): array
    {
        $config = $this->channelConfig($channel);
        /** @var Model $model */
        $model = new $config['model'];

        return array_values(array_filter(
            $model->getFillable(),
            fn (string $field): bool => $field !== 'team_id'
        ));
    }

    private function serializeSettings(Model $settings): array
    {
        exposeSensitiveFields($settings);

        $settings->makeHidden(['team']);

        return serializeApiResponse($settings)->toArray();
    }

    private function resolveSettings(string $channel, int $teamId): Model
    {
        $config = $this->channelConfig($channel);
        $modelClass = $config['model'];

        /** @var Model $settings */
        $settings = $modelClass::query()->firstOrCreate(['team_id' => $teamId]);
        $settings->setRelation('team', Team::query()->findOrFail($teamId));

        return $settings;
    }

    private function showChannel(string $channel): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $settings = $this->resolveSettings($channel, $teamId);
        $this->authorize('view', $settings);

        return response()->json($this->serializeSettings($settings));
    }

    private function updateChannel(Request $request, string $channel): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $allowedFields = $this->allowedFields($channel);
        $body = $request->json()->all();
        $config = $this->channelConfig($channel);

        $validator = Validator::make($body, $config['rules']);

        $extraFields = array_diff(array_keys($body), $allowedFields);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            if (! empty($extraFields)) {
                foreach ($extraFields as $field) {
                    $errors->add($field, 'This field is not allowed.');
                }
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $settings = $this->resolveSettings($channel, $teamId);
        $this->authorize('update', $settings);

        $settings->fill(array_intersect_key($body, array_flip($allowedFields)));
        $settings->save();

        auditLog("api.notifications.{$channel}.updated", [
            'team_id' => $teamId,
            'changed_fields' => array_values(array_intersect($allowedFields, array_keys($body))),
        ]);

        $settings->refresh();
        $settings->setRelation('team', Team::query()->findOrFail($teamId));

        return response()->json($this->serializeSettings($settings));
    }

    #[OA\Get(
        summary: 'Get email notification settings',
        description: 'Get the current team email notification settings, including `smtp_ehlo_domain`, the hostname sent with SMTP EHLO. Encrypted secrets are only returned when the token has `read:sensitive` (or `root`) and the user is a team admin/owner.',
        path: '/notifications/email',
        operationId: 'get-current-team-email-notifications',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        responses: [
            new OA\Response(response: 200, description: 'Email notification settings.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
        ]
    )]
    public function email(Request $request): JsonResponse
    {
        return $this->showChannel('email');
    }

    #[OA\Patch(
        summary: 'Update email notification settings',
        description: 'Update the current team email notification settings. Set `smtp_ehlo_domain` to a valid hostname to control the SMTP EHLO domain, or `null` to use the system default.',
        path: '/notifications/email',
        operationId: 'update-current-team-email-notifications',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        responses: [
            new OA\Response(response: 200, description: 'Updated email notification settings.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 403, description: 'Forbidden.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function update_email(Request $request): JsonResponse
    {
        return $this->updateChannel($request, 'email');
    }

    #[OA\Get(
        summary: 'Get Discord notification settings',
        description: 'Get the current team Discord notification settings. Encrypted secrets are only returned when the token has `read:sensitive` (or `root`) and the user is a team admin/owner.',
        path: '/notifications/discord',
        operationId: 'get-current-team-discord-notifications',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        responses: [
            new OA\Response(response: 200, description: 'Discord notification settings.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
        ]
    )]
    public function discord(Request $request): JsonResponse
    {
        return $this->showChannel('discord');
    }

    #[OA\Patch(
        summary: 'Update Discord notification settings',
        description: 'Update the current team Discord notification settings.',
        path: '/notifications/discord',
        operationId: 'update-current-team-discord-notifications',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        responses: [
            new OA\Response(response: 200, description: 'Updated Discord notification settings.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 403, description: 'Forbidden.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function update_discord(Request $request): JsonResponse
    {
        return $this->updateChannel($request, 'discord');
    }

    #[OA\Get(
        summary: 'Get Slack notification settings',
        description: 'Get the current team Slack notification settings. Encrypted secrets are only returned when the token has `read:sensitive` (or `root`) and the user is a team admin/owner.',
        path: '/notifications/slack',
        operationId: 'get-current-team-slack-notifications',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        responses: [
            new OA\Response(response: 200, description: 'Slack notification settings.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
        ]
    )]
    public function slack(Request $request): JsonResponse
    {
        return $this->showChannel('slack');
    }

    #[OA\Patch(
        summary: 'Update Slack notification settings',
        description: 'Update the current team Slack notification settings.',
        path: '/notifications/slack',
        operationId: 'update-current-team-slack-notifications',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        responses: [
            new OA\Response(response: 200, description: 'Updated Slack notification settings.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 403, description: 'Forbidden.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function update_slack(Request $request): JsonResponse
    {
        return $this->updateChannel($request, 'slack');
    }

    #[OA\Get(
        summary: 'Get Telegram notification settings',
        description: 'Get the current team Telegram notification settings. Encrypted secrets are only returned when the token has `read:sensitive` (or `root`) and the user is a team admin/owner.',
        path: '/notifications/telegram',
        operationId: 'get-current-team-telegram-notifications',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        responses: [
            new OA\Response(response: 200, description: 'Telegram notification settings.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
        ]
    )]
    public function telegram(Request $request): JsonResponse
    {
        return $this->showChannel('telegram');
    }

    #[OA\Patch(
        summary: 'Update Telegram notification settings',
        description: 'Update the current team Telegram notification settings.',
        path: '/notifications/telegram',
        operationId: 'update-current-team-telegram-notifications',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        responses: [
            new OA\Response(response: 200, description: 'Updated Telegram notification settings.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 403, description: 'Forbidden.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function update_telegram(Request $request): JsonResponse
    {
        return $this->updateChannel($request, 'telegram');
    }

    #[OA\Get(
        summary: 'Get Pushover notification settings',
        description: 'Get the current team Pushover notification settings. Encrypted secrets are only returned when the token has `read:sensitive` (or `root`) and the user is a team admin/owner.',
        path: '/notifications/pushover',
        operationId: 'get-current-team-pushover-notifications',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        responses: [
            new OA\Response(response: 200, description: 'Pushover notification settings.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
        ]
    )]
    public function pushover(Request $request): JsonResponse
    {
        return $this->showChannel('pushover');
    }

    #[OA\Patch(
        summary: 'Update Pushover notification settings',
        description: 'Update the current team Pushover notification settings.',
        path: '/notifications/pushover',
        operationId: 'update-current-team-pushover-notifications',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        responses: [
            new OA\Response(response: 200, description: 'Updated Pushover notification settings.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 403, description: 'Forbidden.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function update_pushover(Request $request): JsonResponse
    {
        return $this->updateChannel($request, 'pushover');
    }

    #[OA\Get(
        summary: 'Get webhook notification settings',
        description: 'Get the current team webhook notification settings. Encrypted secrets are only returned when the token has `read:sensitive` (or `root`) and the user is a team admin/owner.',
        path: '/notifications/webhook',
        operationId: 'get-current-team-webhook-notifications',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        responses: [
            new OA\Response(response: 200, description: 'Webhook notification settings.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
        ]
    )]
    public function webhook(Request $request): JsonResponse
    {
        return $this->showChannel('webhook');
    }

    #[OA\Patch(
        summary: 'Update webhook notification settings',
        description: 'Update the current team webhook notification settings.',
        path: '/notifications/webhook',
        operationId: 'update-current-team-webhook-notifications',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        responses: [
            new OA\Response(response: 200, description: 'Updated webhook notification settings.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 403, description: 'Forbidden.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function update_webhook(Request $request): JsonResponse
    {
        return $this->updateChannel($request, 'webhook');
    }
}
