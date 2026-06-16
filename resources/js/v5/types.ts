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
    sshUser: string;
    sshPort: number;
    status: string;
    capabilities: string[];
    builderEnabled: boolean;
    builderCapacity: number;
    privateKeyName: string | null;
    lastBootstrappedAt: string | null;
};

export type V5Cluster = {
    id: string;
    name: string;
    description: string | null;
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

export type V5HomeProps = {
    flux: FluxStatus | null;
    clusters?: V5Cluster[];
    projects?: V5Project[];
    selectedProjectUuid?: string | null;
    selectedEnvironmentUuid?: string | null;
};

export type SelectItemOption = {
    label: string;
    value: string;
};
