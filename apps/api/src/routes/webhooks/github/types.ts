export interface InstallGithub {
    Querystring: {
        gitSourceId: string,
        installation_id: string
    }
}
export interface GitHubEvents {
    Body: {
        number: string,
        action: string,
        repository: string,
        ref: string,
        pull_request: {
            head: {
                ref: string,
                repo: string
            }
        }
    }
}