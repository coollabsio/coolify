export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    two_factor_confirmed_at: string | null;
    force_password_reset: boolean;
    marketing_emails: boolean;
    teams: Team[];
}

export interface Team {
    id: number;
    name: string;
    description: string | null;
    personal_team: boolean;
    created_at: string;
    updated_at: string;
    show_boarding: boolean;
    custom_server_limit: number | null;
    pivot: Pivot;
}

export interface Pivot {
    user_id: number;
    team_id: number;
    role: string;
}
