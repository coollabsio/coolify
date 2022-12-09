import { OnlyId } from "../../../../types"

export interface SaveSettings {
    Body: {
        previewSeparator: string,
        numberOfDockerImagesKeptLocally: number,
        doNotTrack: boolean,
        fqdn: string,
        isAPIDebuggingEnabled: boolean,
        isRegistrationEnabled: boolean,
        dualCerts: boolean,
        minPort: number,
        maxPort: number,
        isAutoUpdateEnabled: boolean,
        isDNSCheckEnabled: boolean,
        DNSServers: string,
        proxyDefaultRedirect: string
    }
}
export interface DeleteDomain {
    Body: {
        fqdn: string
    }
}
export interface CheckDomain extends OnlyId {
    Body: {
        fqdn: string,
        forceSave: boolean,
        dualCerts: boolean,
        isDNSCheckEnabled: boolean,
    }
}
export interface CheckDNS {
    Params: {
        domain: string,
    }
}
export interface SaveSSHKey {
    Body: {
        privateKey: string,
        name: string
    }
}
export interface DeleteSSHKey {
    Body: {
        id: string
    }
}
export interface OnlyIdInBody {
    Body: {
        id: string
    }
}

export interface SetDefaultRegistry {
    Body: {
        id: string
        username: string
        password: string
    }
}
export interface AddDefaultRegistry {
    Body: {
        url: string
        name: string
        username: string
        password: string
    }
}