import { Team } from './TeamType';

export interface PageProps {
    currentTeam: Team;
    // Add other page props as needed
    [key: string]: any; // Add index signature for string keys
}

declare module '@inertiajs/vue3' {
    interface PageProps {
        currentTeam: Team;
        // Add other page props as needed
        [key: string]: any; // Add index signature for string keys
    }
}
