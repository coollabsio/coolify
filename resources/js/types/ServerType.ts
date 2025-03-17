export interface ProxyConfig {
    force_stop: boolean;
    last_applied_settings: string;
    last_saved_settings: string;
    redirect_enabled: boolean;
    redirect_url: string | null;
    status: string;
    type: 'TRAEFIK' | 'CADDY';
}

export interface ServerSettings {
    concurrent_builds: number;
    created_at: string;
    delete_unused_networks: boolean;
    delete_unused_volumes: boolean;
    docker_cleanup_frequency: string;
    docker_cleanup_threshold: number;
    dynamic_timeout: number;
    force_disabled: boolean;
    force_docker_cleanup: boolean;
    generate_exact_labels: boolean;
    id: number;
    is_build_server: boolean;
    is_cloudflare_tunnel: boolean;
    is_jump_server: boolean;
    is_logdrain_axiom_enabled: boolean;
    is_logdrain_custom_enabled: boolean;
    is_logdrain_highlight_enabled: boolean;
    is_logdrain_newrelic_enabled: boolean;
    is_metrics_enabled: boolean;
    is_reachable: boolean;
    is_sentinel_debug_enabled: boolean;
    is_sentinel_enabled: boolean;
    is_swarm_manager: boolean;
    is_swarm_worker: boolean;
    is_usable: boolean;
    logdrain_axiom_api_key: string | null;
    logdrain_axiom_dataset_name: string | null;
    logdrain_custom_config: string | null;
    logdrain_custom_config_parser: string | null;
    logdrain_highlight_project_id: string | null;
    logdrain_newrelic_base_uri: string | null;
    logdrain_newrelic_license_key: string | null;
    sentinel_custom_url: string;
    sentinel_metrics_history_days: number;
    sentinel_metrics_refresh_rate_seconds: number;
    sentinel_push_interval_seconds: number;
    sentinel_token: string;
    server_disk_usage_check_frequency: string;
    server_disk_usage_notification_threshold: number;
    server_id: number;
    server_timezone: string;
    updated_at: string;
    wildcard_domain: string;
}

export interface StandaloneDocker {
    created_at: string;
    id: number;
    name: string;
    network: string;
    server_id: number;
    updated_at: string;
    uuid: string;
}

export interface Server {
    created_at: string;
    deleted_at: string | null;
    description: string;
    high_disk_usage_notification_sent: boolean;
    id: number;
    ip: string;
    is_coolify_host: boolean;
    log_drain_notification_sent: boolean;
    name: string;
    port: number;
    private_key_id: number;
    proxy: ProxyConfig;
    sentinel_updated_at: string;
    settings: ServerSettings;
    standalone_dockers: StandaloneDocker[];
    swarm_cluster: null;
    swarm_dockers: any[];
    team_id: number;
    unreachable_count: number;
    unreachable_notification_sent: boolean;
    updated_at: string;
    user: string;
    uuid: string;
    validation_logs: null;
    privateKey: PrivateKey;
}

export interface PrivateKey {
    id: number;
    uuid: string;
    name: string;
}

export interface ServerSettings {
    wildcard_domain: string;
    server_timezone: string;
    force_docker_cleanup: boolean;
    docker_cleanup_frequency: string;
    docker_cleanup_threshold: number;
    delete_unused_volumes: boolean;
    delete_unused_networks: boolean;
    server_disk_usage_notification_threshold: number;
    server_disk_usage_check_frequency: string;
}
