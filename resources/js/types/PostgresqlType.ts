export interface Postgresql {
    config_hash: string;
    created_at: string;
    custom_docker_run_options: string | null;
    deleted_at: string | null;
    description?: string;
    destination_id: number;
    destination_type: string;
    environment_id: number;
    href_link: string;
    id: number;
    image: string;
    init_scripts: string | null;
    is_include_timestamps: boolean;
    is_log_drain_enabled: boolean;
    is_public: boolean;
    last_online_at: string;
    limits_cpu_shares: number;
    limits_cpus: string;
    limits_cpuset: string | null;
    limits_memory: string;
    limits_memory_reservation: string;
    limits_memory_swap: string;
    limits_memory_swappiness: number;
    name: string;
    ports_mappings: string | null;
    postgres_conf: string | null;
    postgres_db: string;
    postgres_host_auth_method: string | null;
    postgres_initdb_args: string | null;
    postgres_password: string;
    postgres_user: string;
    public_port: number;
    started_at: string;
    status: string;
    updated_at: string;
    uuid: string;
}

