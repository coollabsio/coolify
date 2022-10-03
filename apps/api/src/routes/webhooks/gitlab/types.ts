export interface ConfigureGitLabApp {
    Querystring: {
        code: string,
        state: string
    }
}
export interface GitLabEvents {
    Body: {
        object_attributes: {
            work_in_progress: string
            source: {
                path_with_namespace: string
            }
            isDraft: string
            action: string
            source_branch: string
            target_branch: string
            iid: string
        },
        project: {
            id: string
        },
        object_kind: string,
        ref: string,
        project_id: string
    }
}