import { OnlyId } from "../../../../types"

export interface SaveTeam extends OnlyId {
    Body: {
        name: string
    }
}
export interface DeleteUserFromTeam {
    Body: {
        uid: string
    },
    Params: {
        id: string
    }
}
export interface InviteToTeam {
    Body: {
        email: string,
        permission: string,
        teamId: string,
        teamName: string
    }
}
export interface BodyId {
    Body: {
        id: string
    }
}
export interface SetPermission {
    Body: {
        userId: string,
        newPermission: string,
        permissionId: string
    }
}