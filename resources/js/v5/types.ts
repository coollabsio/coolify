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
    nodeAddress: string | null;
    wireguardListenPortOverride: number | null;
    wireguardEndpointOverride: string | null;
    wireguardManagementIp: string | null;
    wireguardPublicKey: string | null;
    containerSubnets: Record<string, string> | string[];
    privateKeyName: string | null;
    lastBootstrappedAt: string | null;
    lastStatusCheck: string | null;
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

export type V5DashboardProps = {
    flux: FluxStatus | null;
    clusters?: V5Cluster[];
    privateKeys?: V5PrivateKey[];
    projects?: V5Project[];
    selectedProjectUuid?: string | null;
    selectedEnvironmentUuid?: string | null;
};

export type SelectItemOption = {
    label: string;
    value: string;
};
