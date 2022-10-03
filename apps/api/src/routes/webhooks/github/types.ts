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
        repository: {
            id: string,
        },
        ref: string,
        pull_request: {
            base: {
                ref: string,
                repo: {
                    id: string,
                }
            },
            head: {
                ref: string,
                repo: {
                    id: string,
                    full_name: string,
                }
            }
        }
    }
}