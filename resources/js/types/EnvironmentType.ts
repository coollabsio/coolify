export interface Environment {
    id: number;
    uuid: string;
    name: string;
    description?: string;
    project_id: number;
    created_at: string;
    updated_at: string;
    // Not in database
    project_uuid: string;
}
