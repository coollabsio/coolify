import { OnlyId } from "../../../../types"

export interface SaveSettings {
    Body: {
        fqdn: string,
        isRegistrationEnabled: boolean,
        dualCerts: boolean,
        minPort: number,
        maxPort: number,
        isAutoUpdateEnabled: boolean,
        isDNSCheckEnabled: boolean
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