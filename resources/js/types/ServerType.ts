export interface ProxyConfig {
  type: 'TRAEFIK';
  status: string;
  redirect_enabled: boolean;
  last_saved_settings: string;
  last_applied_settings: string;
  force_stop: boolean;
  redirect_url: string | null;
}

export interface ServerSettings {
  id: number;
  is_swarm_manager: boolean;
  is_jump_server: boolean;
  is_build_server: boolean;
  is_reachable: boolean;
  is_usable: boolean;
  server_id: number;
  created_at: string;
  updated_at: string;
  wildcard_domain: string;
  is_cloudflare_tunnel: boolean;
  is_logdrain_newrelic_enabled: boolean;
  logdrain_newrelic_license_key: string | null;
  logdrain_newrelic_base_uri: string | null;
  is_logdrain_highlight_enabled: boolean;
  logdrain_highlight_project_id: string | null;
  is_logdrain_axiom_enabled: boolean;
  logdrain_axiom_dataset_name: string | null;
  logdrain_axiom_api_key: string | null;
  is_swarm_worker: boolean;
  is_logdrain_custom_enabled: boolean;
  logdrain_custom_config: string | null;
  logdrain_custom_config_parser: string | null;
  concurrent_builds: number;
  dynamic_timeout: number;
  force_disabled: boolean;
  is_metrics_enabled: boolean;
  generate_exact_labels: boolean;
  force_docker_cleanup: boolean;
  docker_cleanup_frequency: string;
  docker_cleanup_threshold: number;
  server_timezone: string;
  delete_unused_volumes: boolean;
  delete_unused_networks: boolean;
  is_sentinel_enabled: boolean;
  sentinel_token: string;
  sentinel_metrics_refresh_rate_seconds: number;
  sentinel_metrics_history_days: number;
  sentinel_push_interval_seconds: number;
  sentinel_custom_url: string;
  server_disk_usage_notification_threshold: number;
  is_sentinel_debug_enabled: boolean;
  server_disk_usage_check_frequency: string;
}

export interface StandaloneDocker {
  id: number;
  name: string;
  uuid: string;
  network: string;
  server_id: number;
  created_at: string;
  updated_at: string;
}

export interface Server {
  id: number;
  uuid: string;
  name: string;
  description: string;
  ip: string;
  port: number;
  user: string;
  team_id: number;
  private_key_id: number;
  proxy: ProxyConfig;
  created_at: string;
  updated_at: string;
  unreachable_notification_sent: boolean;
  unreachable_count: number;
  high_disk_usage_notification_sent: boolean;
  log_drain_notification_sent: boolean;
  swarm_cluster: null;
  validation_logs: null;
  sentinel_updated_at: string;
  deleted_at: string | null;
  is_coolify_host: boolean;
  settings: ServerSettings;
  swarm_dockers: any[];
  standalone_dockers: StandaloneDocker[];
}