<?php

return [
    'name' => '服务器名称',
    'general' => '常规',
    'checking_status' => '检查状态中...',
    'refresh_status' => '刷新状态',
    'refreshing_status' => '正在刷新状态',
    'power_on' => '开机',
    'power_off' => '关机',
    'validating' => '验证中...',
    'confirm_settings_change' => '确认服务器设置更改？',
    'misconfigure_warning' => '如果配置错误，您可能会失去 Coolify 的许多功能。',

    // 服务器状态
    'status' => [
        'running' => '运行中',
        'off' => '已关闭',
        'starting' => '启动中',
        'initializing' => '初始化中',
    ],

    // Validate & Install
    'is_reachable' => '服务器可连接：',
    'supported_os_type' => '支持的操作系统类型：',
    'prerequisites_installed' => '已安装依赖项：',
    'docker_installed' => '已安装 Docker：',
    'docker_compose_installed' => '已安装 Docker Compose：',
    'docker_version' => 'Docker 最低版本：',
    'installation_logs' => '安装日志',
    'retry_validation' => '重试验证',
    'validate_warning' => '这将重新验证服务器，安装/更新 Docker Engine、Docker Compose 和所有相关配置。它还将重新启动 Docker Engine，因此您的正在运行的容器暂时无法访问。',

    // Advanced
    'advanced_config_desc' => '服务器的高级配置。',
    'disk_usage' => '磁盘使用情况',
    'disk_usage_check_frequency' => '磁盘使用检查频率',
    'disk_usage_check_frequency_helper' => '磁盘使用检查频率的 Cron 表达式。<br>您可以使用 every_minute, hourly, daily, weekly, monthly, yearly。<br><br>默认为每晚 11:00 PM。',
    'disk_usage_threshold' => '服务器磁盘使用通知阈值 (%)',
    'disk_usage_threshold_helper' => '如果服务器磁盘使用率超过此阈值，Coolify 将向团队成员发送通知。',
    'builds' => '构建',
    'concurrent_builds' => '并发构建数',
    'concurrent_builds_helper' => '您可以指定并发运行的同时构建进程/部署的数量。',
    'deployment_timeout' => '部署超时（秒）',
    'deployment_timeout_helper' => '您可以定义部署在超时前运行的最长持续时间。',
    'deployment_queue_limit' => '部署队列限制',
    'deployment_queue_limit_helper' => '允许排队的最大部署数量。达到限制后，新部署将被拒绝并返回 429 状态。',

    // Docker Cleanup
    'docker_cleanup' => 'Docker 清理',
    'confirm_docker_cleanup' => '确认 Docker 清理？',
    'trigger_manual_cleanup' => '触发手动清理',
    'trigger_docker_cleanup' => '触发 Docker 清理',
    'docker_cleanup_desc' => '配置服务器的 Docker 清理设置。',
    'cleanup_configuration' => '清理配置',
    'docker_cleanup_frequency' => 'Docker 清理频率',
    'docker_cleanup_frequency_helper' => 'Docker 清理的 Cron 表达式。<br>您可以使用 every_minute, hourly, daily, weekly, monthly, yearly。<br><br>默认为每晚午夜。',
    'docker_cleanup_threshold' => 'Docker 清理阈值 (%)',
    'docker_cleanup_threshold_helper' => '当磁盘使用率超过此阈值时，Docker 清理任务将运行。',
    'force_docker_cleanup' => '强制 Docker 清理',
    'force_docker_cleanup_helper' => '启用强制 Docker 清理或手动触发清理将执行以下操作：<ul class="list-disc pl-4 mt-2"><li>移除 Coolify 管理的已停止容器（因容器非持久化，数据不会丢失）。</li><li>删除未使用的镜像。</li><li>清除构建缓存。</li><li>移除 Coolify 辅助镜像的旧版本。</li><li>（如果在高级选项中启用）可选删除未使用的卷。</li><li>（如果在高级选项中启用）可选删除未使用的网络。</li></ul>',
    'delete_unused_volumes' => '删除未使用的卷',
    'delete_unused_volumes_helper' => '此选项将在清理期间删除所有未使用的 Docker 卷。<br><br><strong>警告：已停止容器的数据将丢失！</strong><br><br>后果包括：<ul class="list-disc pl-4 mt-2"><li>未附加到正在运行的容器的卷将被永久删除（已停止容器的卷受影响）。</li><li>存储在已删除卷中的数据无法恢复。</li></ul>',
    'delete_unused_networks' => '删除未使用的网络',
    'delete_unused_networks_helper' => '此选项将在清理期间删除所有未使用的 Docker 网络。<br><br><strong>警告：功能可能会丢失，容器可能无法相互通信！</strong><br><br>后果包括：<ul class="list-disc pl-4 mt-2"><li>未附加到正在运行的容器的网络将被永久删除（已停止容器使用的网络受影响）。</li><li>如果删除了所需的网络，容器可能会丢失连接。</li></ul>',
    'disable_image_retention' => '禁用应用镜像保留',
    'disable_image_retention_helper' => '启用后，Docker 清理将删除所有旧的应用程序镜像，无论每个应用程序的保留设置如何。只有当前运行的镜像将被保留。<br><br><strong>警告：这将禁用此服务器上所有应用程序的回滚功能。</strong>',
    'recent_executions' => '最近执行记录',
    'click_to_check_output' => '（点击查看输出）',
    'caution_text' => '这些选项可能会导致永久性数据丢失和功能问题。只有在您完全了解后果的情况下才启用。',
    'proxy_settings_desc' => '配置您的代理设置和高级选项。',
    // Modal Actions
    'cleanup_action_1' => '永久删除 Coolify 管理的所有已停止容器（因容器非持久化，无数据丢失）',
    'cleanup_action_2' => '永久删除所有未使用的镜像',
    'cleanup_action_3' => '清除构建缓存',
    'cleanup_action_4' => '移除 Coolify 辅助镜像的旧版本',
    'cleanup_action_5' => '可选地永久删除所有未使用的卷（如果在高级选项中启用）。',
    'cleanup_action_6' => '可选地永久删除所有未使用的网络（如果在高级选项中启用）。',

    // Proxy
    'switch_proxy' => '切换代理',
    'reset_configuration' => '重置配置',
    'config_out_of_sync' => '配置不同步',
    'proxy_config_out_of_sync_desc' => '保存的代理配置与当前运行的配置不同。重启代理以应用您的更改。',
    'generate_exact_labels' => '仅为 :proxy 生成标签',
    'generate_exact_labels_helper' => '如果设置，所有资源将仅具有 :proxy 的 Docker 容器标签。<br>对于应用程序，需要手动重新生成标签。<br>资源需要重启。',
    'override_request_handler' => '覆盖默认请求处理程序',
    'override_request_handler_helper' => '对未知主机或已停止服务的请求将收到 503 响应，或被重定向到您在下面设置的 URL（需要先启用此选项）。',
    'redirect_to' => '重定向到（可选）',
    'configuration_file' => '配置文件 ( :path )',
    'loading_proxy_config' => '正在加载代理配置...',
    'select_proxy' => '选择您想在此服务器上使用的代理。',
    'custom_none_proxy' => '自定义 (无) 代理已选择',
    'permission_required' => '需要权限',
    'permission_required_desc' => '您没有权限为此服务器配置代理设置。',
    
    // Modal Proxy Switch
    'confirm_proxy_switch' => '确认切换代理？',
    'proxy_switch_warning' => '自定义代理配置可能会重置为其默认设置。',
    'proxy_switch_warning_2' => '此操作可能会导致问题。在继续之前，请参考<a href="https://coolify.io/docs/knowledge-base/server/proxies#switch-between-proxies" target="_blank" class="underline text-white">代理切换指南</a>！',
    
    // Modal Reset Config
    'confirm_reset_config' => '确认重置代理配置？',
    'reset_config_action_1' => '将代理配置重置为默认设置',
    'reset_config_action_2' => '所有自定义配置将丢失',
    'reset_config_action_3' => '自定义端口和入口点将被移除',
    'confirm_label_server_name' => '请在下方输入服务器名称以确认',
    'server_name_label' => '服务器名称',

    // Traefik Warnings
    'traefik_latest_tag_title' => '正在使用 "latest" Traefik 标签',
    'traefik_latest_tag_desc' => '您的代理容器正在运行 <span class="font-mono">latest</span> 标签。虽然这确保了您总是拥有最新版本，但这可能会引入意外的破坏性更改。<br><br><strong>建议：</strong> 固定到特定版本（例如 <span class="font-mono">traefik::version</span>）以确保稳定性和可预测的更新。',
    'traefik_patch_available_title' => 'Traefik 补丁更新可用',
    'traefik_patch_available_desc' => '您的 Traefik 代理容器正在运行版本 <span class="font-mono">v:current</span>，但版本 <span class="font-mono">:latest</span> 可用。<br><br><strong>建议：</strong> 更新到最新的补丁版本以获取安全修复和错误修复。请先在非生产环境中测试。',
    'traefik_minor_available_title' => '新的 Traefik 次要版本可用',
    'traefik_minor_available_desc' => 'Traefik 的新次要版本可用：<span class="font-mono">:new</span><br><br>您当前正在运行 <span class="font-mono">v:current</span>。升级到 <span class="font-mono">:new</span> 将为您提供新功能和改进。<br><br><strong>重要：</strong> 在升级到新的次要版本之前，请阅读 <a href="https://github.com/traefik/traefik/releases" target="_blank" class="underline text-white">Traefik 更新日志</a> 以了解破坏性更改和新功能。<br><br><strong>建议：</strong> 先在非生产环境中测试升级。',

    // Resources
    'resources_managed_desc' => '这里您可以找到所有由 Coolify 管理的资源。',

    // Cloudflare Tunnel
    'disable_cloudflare_tunnel' => '禁用 Cloudflare Tunnel',
    'confirm_disable_cloudflare_tunnel' => '确认禁用 Cloudflare Tunnel？',
    'disable_cloudflare_tunnel_action_1' => '此服务器的 Cloudflare Tunnel 将被禁用。',
    'disable_cloudflare_tunnel_action_2' => '服务器 IP 地址将更新为其之前的 IP 地址。',
    'manually_configured_cloudflare_tunnel' => '我已手动配置 Cloudflare Tunnel',
    'confirm_manually_configured_cloudflare_tunnel' => '您已手动配置 Cloudflare Tunnel？',

    // Security Patches
    'confirm_package_update' => '确认更新软件包？',
    'update_all_packages' => '全部更新软件包',

    // Sentinel
    'regenerate_sentinel_token' => '重新生成 Sentinel Token',
];