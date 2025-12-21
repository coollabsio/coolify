<?php

return [
    'placeholders' => [
        // 邮箱相关
        'email' => '邮箱',
        'test_email' => 'test@example.com',

        // 密码相关
        'password' => '密码',
        'new_password' => '新密码',
        'confirm_password' => '确认新密码',
        'enter_password' => '输入密码',

        // SMTP 设置
        'smtp_host' => 'smtp.mailgun.org',
        'smtp_port' => '587',
        'api_key' => 'API 密钥',
        'enter_api_token' => '输入您的 API Token',

        // 搜索
        'search' => '搜索',
        'search_updates' => '搜索更新...',
        'search_user' => '搜索用户',
        'global_search' => '搜索资源、路径、所有内容（输入 new 创建新资源）...',

        // GitHub 相关
        'github_org_hint' => '如果为空，将使用您的 GitHub 用户。',
        'github_user_hint' => '如果为空，将使用个人用户',

        // 项目相关
        'project_name' => '您的酷项目',
        'project_description' => '这是我的酷项目，大家都知道',
        'environment_name' => 'production',

        // 帮助相关
        'help_subject' => '需要帮助...',
        'help_message' => '遇到问题... 请尽可能提供详细信息。',

        // 云提供商
        'cloud_token_name' => '例如：生产环境 Hetzner。提示：添加 Hetzner 项目名称以便识别',

        // Telegram
        'telegram_thread_id' => '自定义 Telegram 话题 ID',

        // 服务器相关
        'server_timezone' => '服务器时区',
        'no_timezone_set' => '未设置时区',
        'script_name' => '脚本名称...',

        // 数据库相关
        'human_readable_name' => '易读名称',
        'run_cron' => '运行 Cron',

        // 搜索相关
        'type_to_search' => '输入 / 搜索...',
        'search_categories' => '搜索类别...',
        'search_name_fqdn' => '搜索名称、FQDN...',
        'create_database_test' => 'CREATE DATABASE test;',

        // 证书相关
        'paste_ca_certificate' => '在此粘贴或编辑 CA 证书内容...',
    ],

    // 表单标签
    'provider' => '提供商',
    'host' => '主机',
    'port' => '端口',
    'encryption' => '加密',
    'starttls' => 'StartTLS',
    'tls_ssl' => 'TLS/SSL',
    'none' => '无',
    'smtp_username' => 'SMTP 用户名',
    'smtp_password' => 'SMTP 密码',
    'timeout' => '超时',
    'api_key' => 'API 密钥',
];
