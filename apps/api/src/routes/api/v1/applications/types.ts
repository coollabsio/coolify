import type { OnlyId } from "../../../../types";

export interface SaveApplication extends OnlyId {
    Body: {
        name: string,
        buildPack: string,
        fqdn: string,
        port: number,
        exposePort: number,
        installCommand: string,
        buildCommand: string,
        startCommand: string,
        baseDirectory: string,
        publishDirectory: string,
        pythonWSGI: string,
        pythonModule: string,
        pythonVariable: string,
        dockerFileLocation: string,
        denoMainFile: string,
        denoOptions: string,
        baseImage: string,
        gitCommitHash: string,
        baseBuildImage: string,
        deploymentType: string,
        baseDatabaseBranch: string,
        dockerComposeFile: string,
        dockerComposeFileLocation: string,
        dockerComposeConfiguration: string,
        simpleDockerfile: string,
        dockerRegistryImageName: string
    }
}
export interface SaveApplicationSettings extends OnlyId {
    Querystring: { domain: string; };
    Body: { debug: boolean; previews: boolean; dualCerts: boolean; autodeploy: boolean; branch: string; projectId: number; isBot: boolean; isDBBranching: boolean, isCustomSSL: boolean };
}
export interface DeleteApplication extends OnlyId {
    Querystring: { domain: string; };
    Body: { force: boolean }
}
export interface CheckDomain extends OnlyId {
    Querystring: { domain: string; };
}
export interface CheckDNS extends OnlyId {
    Querystring: { domain: string; };
    Body: {
        exposePort: number,
        fqdn: string,
        forceSave: boolean,
        dualCerts: boolean
    }
}
export interface DeployApplication {
    Querystring: { domain: string }
    Body: { pullmergeRequestId: string | null, branch: string, forceRebuild?: boolean }
}
export interface GetImages {
    Body: { buildPack: string, deploymentType: string }
}
export interface SaveApplicationSource extends OnlyId {
    Body: { gitSourceId?: string | null, forPublic?: boolean, type?: string, simpleDockerfile?: string }
}
export interface CheckRepository extends OnlyId {
    Querystring: { repository: string, branch: string }
}
export interface SaveDestination extends OnlyId {
    Body: { destinationId: string }
}
export interface SaveSecret extends OnlyId {
    Body: {
        name: string,
        value: string,
        isBuildSecret: boolean,
        previewSecret: boolean,
        isNew: boolean
    }
}
export interface DeleteSecret extends OnlyId {
    Body: { name: string }
}
export interface SaveStorage extends OnlyId {
    Body: {
        path: string,
        newStorage: boolean,
        storageId: string
    }
}
export interface DeleteStorage extends OnlyId {
    Body: {
        path: string,
    }
}
export interface GetApplicationLogs {
    Params: {
        id: string,
        containerId: string
    }
    Querystring: {
        since: number,
    }
}
export interface GetBuilds extends OnlyId {
    Querystring: {
        buildId: string
        skip: number,
    }
}
export interface GetBuildIdLogs {
    Params: {
        id: string,
        buildId: string
    },
    Querystring: {
        sequence: number
    }
}
export interface SaveDeployKey extends OnlyId {
    Body: {
        deployKeyId: number
    }
}
export interface CancelDeployment {
    Body: {
        buildId: string,
        applicationId: string
    }
}
export interface DeployApplication extends OnlyId {
    Body: {
        pullmergeRequestId: string | null,
        branch: string,
        forceRebuild?: boolean
    }
}

export interface StopPreviewApplication extends OnlyId {
    Body: {
        pullmergeRequestId: string | null,
    }
}
export interface RestartPreviewApplication {
    Params: {
        id: string,
        pullmergeRequestId: string | null,
    }
}
export interface RestartApplication {
    Params: {
        id: string,
    },
    Body: {
        imageId: string | null,
    }
}