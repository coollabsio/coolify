import { OnlyId } from "../../../../types"

export interface SaveSettings {
    Body: {
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