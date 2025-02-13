import { Environment } from "./EnvironmentType";

export interface Project {
    id: number;
    uuid: string;
    name: string;
    description: string;
    team_id: number;
    created_at: string;
    updated_at: string;
    environments: Environment[];
}

