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
];