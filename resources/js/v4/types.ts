export type V4DashboardProject = {
    uuid: string;
    name: string;
    description: string | null;
    url: string;
    firstEnvironmentUuid: string | null;
    canUpdate: boolean;
    resourceCreateUrl: string | null;
    settingsUrl: string;
};

export type V4DashboardServer = {
    uuid: string;
    name: string;
    description: string | null;
    url: string;
    isReachable: boolean;
    isUsable: boolean;
    forceDisabled: boolean;
};

export type V4DashboardPermissions = {
    createProject: boolean;
    createServer: boolean;
    createAnyResource: boolean;
};

export type V4DashboardLinks = {
    onboarding: string;
    serverCreate: string;
    dashboard: string;
    uiMode: string;
};

export type V4DashboardProps = {
    projects: V4DashboardProject[];
    servers: V4DashboardServer[];
    privateKeysCount: number;
    permissions: V4DashboardPermissions;
    links: V4DashboardLinks;
    flash: {
        error: string | null;
    };
};
