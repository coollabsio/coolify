import { OnlyId } from "../../../../types"

export interface CheckDestination {
    Body: {
        network: string
    }
}
export interface NewDestination extends OnlyId {
    Body: {
        name: string
        network: string
        engine: string
        isCoolifyProxyUsed: boolean
    }
}
export interface SaveDestinationSettings extends OnlyId {
    Body: {
        engine: string
        isCoolifyProxyUsed: boolean
    }
}
export interface Proxy extends OnlyId {
    Body: {
        engine: string
    }
}