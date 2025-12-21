<?php

return [
    'title' => '通知',
    'discord' => 'Discord',
    'email' => '邮箱',
    'pushover' => 'Pushover',
    'slack' => 'Slack',
    'telegram' => 'Telegram',
    'webhook' => 'Webhook',
    'test_notification' => '测试通知',
    'send_test' => '发送测试',

    // Email 通知页面
    'send_email' => '发送邮件',
    'recipient' => '收件人',
    'smtp_server' => 'SMTP 服务器',
    'resend' => 'Resend',
    'settings' => '通知设置',
    'select_events_hint' => '选择您希望接收邮件通知的事件。',
    'use_system_email' => '使用系统级（事务性）邮件设置',
    'use_hosted_email' => '使用托管邮件服务',
    'copy_from_instance' => '从实例设置复制',
    'from_name' => '发送者名称',
    'from_name_helper' => '邮件中使用的名称。',
    'from_address' => '发送者地址',
    'from_address_helper' => '邮件中使用的邮箱地址。',
    'timeout_helper' => '发送邮件的超时值。',

    // 通知事件
    'deployment_success' => '部署成功',
    'deployment_failure' => '部署失败',
    'status_change_hint' => '在容器状态更改时发送电子邮件。它将为容器的停止和重新启动事件发送电子邮件。',
    'container_status_changes' => '容器状态更改',
    'backup_success' => '备份成功',
    'backup_failure' => '备份失败',
    'scheduled_task_success' => '定时任务成功',
    'scheduled_task_failure' => '定时任务失败',
    'docker_cleanup_success' => 'Docker 清理成功',
    'docker_cleanup_failure' => 'Docker 清理失败',
    'server_disk_usage' => '服务器磁盘使用',
    'server_reachable' => '服务器可达',
    'server_unreachable' => '服务器不可达',
    'server_patching' => '服务器补丁',
    'traefik_outdated' => 'Traefik 代理已过时',

    // 分类标题
    'deployments' => '部署',
    'backups' => '备份',
    'scheduled_tasks' => '定时任务',
    'server' => '服务器',
    
    // 通用通知设置
    'notification_settings' => '通知设置',
    'select_events_for_discord' => '选择您希望接收 Discord 通知的事件。',
    'select_events_for_telegram' => '选择您希望接收 Telegram 通知的事件。',
    'select_events_for_slack' => '选择您希望接收 Slack 通知的事件。',
    'select_events_for_pushover' => '选择您希望接收 Pushover 通知的事件。',
    'select_events_for_webhook' => '选择您希望接收 Webhook 通知的事件。',
    'send_test_notification' => '发送测试通知',
    
    // Discord 特定
    'ping_enabled' => '启用 Ping',
    'ping_enabled_helper' => '如果启用，当发生关键事件时，通知中将发送 ping（@here）。',
    'webhook' => 'Webhook',
    'webhook_helper_discord' => '创建 Discord 服务器并生成 Webhook URL。<br><a class=\'inline-block underline dark:text-white\' href=\'https://support.discord.com/hc/en-us/articles/228383668-Intro-to-Webhooks\' target=\'_blank\'>Webhook 文档</a>',
    
    // Telegram 特定
    'bot_api_token' => 'Bot API Token',
    'bot_api_token_helper' => '从 Telegram 上的 <a class=\'inline-block underline dark:text-white\' href=\'https://t.me/botfather\' target=\'_blank\'>BotFather Bot</a> 获取。',
    'chat_id' => 'Chat ID',
    'chat_id_helper' => '将您的机器人添加到群聊中，并在此处添加其 Chat ID。',
    
    // Slack 特定
    'webhook_helper_slack' => '创建 Slack APP 并生成 Incoming Webhook URL。<br><a class=\'inline-block underline dark:text-white\' href=\'https://api.slack.com/apps\' target=\'_blank\'>创建 Slack APP</a>',
    
    // Pushover 特定
    'user_key' => 'User Key',
    'user_key_helper' => '在 Pushover 中获取您的 User Key。您需要登录 Pushover 才能在右上角看到您的用户密钥。<br><a class=\'inline-block underline dark:text-white\' href=\'https://pushover.net/\' target=\'_blank\'>Pushover 仪表板</a>',
    'api_token' => 'API Token',
    'api_token_helper' => '通过在 Pushover 中创建新应用程序来生成 API Token/Key。<br><a class=\'inline-block underline dark:text-white\' href=\'https://pushover.net/apps/build\' target=\'_blank\'>创建 Pushover 应用程序</a>',
    
    // Webhook 特定
    'webhook_url_post' => 'Webhook URL (POST)',
    'webhook_url_helper' => '输入有效的 HTTP 或 HTTPS URL。当事件发生时，Coolify 将向此端点发送 POST 请求。',
];