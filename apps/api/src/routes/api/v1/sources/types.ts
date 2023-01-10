import { OnlyId } from "../../../../types";

export interface SaveGitHubSource extends OnlyId {
    Body: {
        name: string,
        htmlUrl: string,
        apiUrl: string,
        organization: string,
        customPort: number,
        isSystemWide: boolean
    }
}
export interface SaveGitLabSource extends OnlyId {
    Body: {
        type: string,
        name: string,
        htmlUrl: string,
        apiUrl: string,
        oauthId: number,
        appId: string,
        appSecret: string,
        groupName: string,
        customPort: number,
        customUser: string,
    }
}
export interface CheckGitLabOAuthId extends OnlyId {
    Body: {
        oauthId: number,
    }
}