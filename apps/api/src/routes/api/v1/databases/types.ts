import type { OnlyId } from "../../../../types";

export interface SaveDatabaseType extends OnlyId {
    Body: { type: string }
}
export interface DeleteDatabase extends OnlyId {
    Body: { force: string }
}
export interface SaveVersion extends OnlyId {
    Body: {
        version: string
    }
}
export interface SaveDatabaseDestination extends OnlyId {
    Body: {
        destinationId: string
    }
}
export interface GetDatabaseLogs extends OnlyId {
    Querystring: {
        since: number
    }
}
export interface SaveDatabase extends OnlyId {
    Body: {
        name: string,
        defaultDatabase: string,
        dbUser: string,
        dbUserPassword: string,
        rootUser: string,
        rootUserPassword: string,
        version: string,
        isRunning: boolean
    }
}
export interface SaveDatabaseSettings extends OnlyId {
    Body: {
        isPublic: boolean,
        appendOnly: boolean
    }
}

export interface SaveDatabaseSecret extends OnlyId {
    Body: {
        name: string,
        value: string,
        isNew: string,
    }
}
export interface DeleteDatabaseSecret extends OnlyId {
    Body: {
        name: string,
    }
}

