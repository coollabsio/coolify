import { OnlyId } from "../../../../types";

export interface SaveServiceType extends OnlyId {
    Body: {
        type: string
    }
}
export interface SaveServiceVersion extends OnlyId {
    Body: {
        version: string
    }
}
export interface SaveServiceDestination extends OnlyId {
    Body: {
        destinationId: string
    }
}
export interface GetServiceLogs{
    Params: { 
        id: string,
        containerId: string
     },
    Querystring: {
        since: number,
    }
}
export interface SaveServiceSettings extends OnlyId {
    Body: {
        dualCerts: boolean
    }
}
export interface CheckServiceDomain extends OnlyId {
    Querystring: {
        domain: string
    }
}
export interface CheckService extends OnlyId {
    Body: {
        fqdn: string,
        forceSave: boolean,
        dualCerts: boolean,
        exposePort: number,
        otherFqdn: boolean
    }
}
export interface SaveService extends OnlyId {
    Body: {
        name: string,
        fqdn: string,
        exposePort: number,
        version: string,
        serviceSetting: any
        type: string
    }
}
export interface SaveServiceSecret extends OnlyId {
    Body: {
        name: string,
        value: string,
        isNew: string,
    }
}
export interface DeleteServiceSecret extends OnlyId {
    Body: {
        name: string,
    }
}
export interface SaveServiceStorage extends OnlyId {
    Body: {
        path: string,
        containerId: string,
        storageId: string,
        isNewStorage: boolean,
    }
}

export interface DeleteServiceStorage extends OnlyId {
    Body: {
        storageId: string,
    }
}
export interface ServiceStartStop {
    Params: {
        id?: string,
        type: string,
    }
}
export interface SetWordpressSettings extends OnlyId {
    Body: {
        ownMysql: boolean
    }
}
export interface ActivateWordpressFtp extends OnlyId {
    Body: {
        ftpEnabled: boolean
    }
}

export interface SetGlitchTipSettings extends OnlyId {
    Body: {
        enableOpenUserRegistration: boolean,
        emailSmtpUseSsl: boolean,
        emailSmtpUseTls: boolean
    }
}
