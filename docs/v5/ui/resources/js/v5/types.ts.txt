export type FluxStatus = {
    available?: boolean;
    label?: string | null;
    message?: string | null;
    socket?: string | null;
};

export type V5Server = {
    id: string;
    name: string;
    host: string;
    status: string;
    capabilities: string[];
    builderEnabled: boolean;
    builderCapacity: number;
    builderCpuQuota: string;
    ingressEnabled: boolean;
    ingressType: string | null;
    uuid: string | null;
    nodeAddress: string | null;
    wireguardListenPortOverride: number | null;
    wireguardEndpointOverride: string | null;
    wireguardManagementIp: string | null;
    wireguardPublicKey: string | null;
    /** Namespace → subnet map; serialized as an empty JSON array when no subnets are recorded. */
    containerSubnets: Record<string, string> | [];
    privateKeyName: string | null;
    lastBootstrappedAt: string | null;
    lastBootstrapAction: string | null;
    lastBootstrapStatus: string | null;
    lastBootstrapOutput: string | null;
    lastBootstrapRanAt: string | null;
    lastStatusOutput: string | null;
    lastStatusCheckedAt: string | null;
};

export type V5Cluster = {
    id: string;
    name: string;
    description: string | null;
    wireguardInterface: string;
    wireguardManagementPool: string;
    wireguardListenPort: number;
    containerNetworkPool: string;
    containerNetworkPrefix: number;
    namespaces: string[];
    defaultDenyContainers: boolean;
    cooldVersion: string;
    corrosionVersion: string;
    corrosionGossipPort: number;
    corrosionApiPort: number;
    builderEnabled: boolean;
    builderCapacity: number;
    builderCpuQuota: string;
    builderMemoryMax: string;
    builderTimeoutSecs: number;
    lastCliAction: string | null;
    lastCliStatus: string | null;
    lastCliSummary: string | null;
    lastCliRanAt: string | null;
    serversCount: number;
    servers: V5Server[];
};

export type V5Environment = {
    uuid: string;
    name: string;
};

export type V5Project = {
    uuid: string;
    name: string;
    environments: V5Environment[];
};

export type V5PrivateKey = {
    id: string;
    name: string;
};

export type V5NginxServer = {
    id: string;
    name: string;
    host: string;
    status: string;
};

export type V5Application = {
    id: string;
    name: string;
    image: string;
    containerName: string;
    status: 'creating' | 'running' | 'failed' | string;
    statusMessage: string | null;
    effectiveStatus: 'creating' | 'running' | 'failed' | 'unknown' | string;
    effectiveStatusMessage: string | null;
    runtimeContainerId: string | null;
    serverName: string | null;
    serverStatus: string | null;
    serverStatusMessage: string | null;
    isServerReachable: boolean;
    serverIngressEnabled: boolean;
    meshNamespace: string;
    ingressEnabled: boolean;
    internalPort: number | null;
    domains: string[];
    meshFqdn: string;
    projectUuid: string | null;
    environmentUuid: string | null;
    canvasX: number;
    canvasY: number;
};

export type V5CaddyIngress = {
    id: string;
    name: string;
    host: string;
    type: string;
    status: string;
    statusMessage: string | null;
    canvasX: number;
    canvasY: number;
};


export type V5ResourceConnection = {
    id: string;
    applicationIds: string[];
    fromApplicationId: string;
    toApplicationId: string;
    portsByDirection: Record<string, string[]>;
};

export type V5DashboardProps = {
    flux: FluxStatus | null;
    currentTeam?: {
        id: number;
    } | null;
    applications?: V5Application[];
    caddyIngresses?: V5CaddyIngress[];
    resourceConnections?: V5ResourceConnection[];
    nginxServers?: V5NginxServer[];
    clusters?: V5Cluster[];
    privateKeys?: V5PrivateKey[];
    projects?: V5Project[];
    selectedProjectUuid?: string | null;
    selectedEnvironmentUuid?: string | null;
    selectedApplicationUuid?: string | null;
};

export type SelectItemOption = {
    label: string;
    value: string;
};
