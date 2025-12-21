<?php

return [
    'no_health_check_configured' => '未配置健康检查。<span class=\'dark:text-warning text-coollabs\'>资源可能正常运行。</span><br><br>Traefik 和 Caddy 即使没有健康检查也会将流量路由到此容器。但是，建议配置健康检查以确保资源在接收流量之前已准备就绪。<br><br>更多详细信息请参阅<a href=\'https://coolify.io/docs/knowledge-base/proxy/traefik/healthchecks\' class=\'underline dark:text-warning text-coollabs\' target=\'_blank\'>文档</a>。',
    'unhealthy_state' => '不健康状态。<span class=\'dark:text-warning text-coollabs\'>健康检查失败。</span><br><br>此资源将<span class=\'dark:text-warning text-coollabs\'>无法与 Traefik 配合使用</span>，因为它期望健康状态。您需要修复健康检查或导致其失败的根本问题。<br><br>更多详细信息请参阅<a href=\'https://coolify.io/docs/knowledge-base/proxy/traefik/healthchecks\' class=\'underline dark:text-warning text-coollabs\' target=\'_blank\'>文档</a>。',
];

