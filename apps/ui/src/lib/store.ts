import { writable, readable, type Writable, type Readable } from 'svelte/store';

interface AppSession {
    version: string | null,
    userId: string | null,
    teamId: string | null,
    permission: string,
    isAdmin: boolean,
    whiteLabeled: boolean,
    whiteLabeledDetails: {
        icon: string | null,
    },
    tokens: {
        github: string | null,
        gitlab: string | null,
    }
}
export const loginEmail: Writable<string | undefined> = writable()
export const appSession: Writable<AppSession> = writable({
    version: null,
    userId: null,
    teamId: null,
    permission: 'read',
    isAdmin: false,
    whiteLabeled: false,
    whiteLabeledDetails: {
        icon: null
    },
    tokens: {
        github: null,
        gitlab: null
    }
});
export const isTraefikUsed: Writable<boolean> = writable(false);
export const disabledButton: Writable<boolean> = writable(false);
export const status: Writable<any> = writable({
    application: {
        isRunning: false,
        isExited: false,
        loading: false,
        initialLoading: true
    },
    service: {
        initialLoading: true,
        loading: false,
        isRunning: false
    }, 
    database: {
        initialLoading: true,
        loading: false,
        isRunning: false
    }

});

export const features = readable({
    beta: window.localStorage.getItem('beta') === 'true',
    latestVersion: window.localStorage.getItem('latestVersion')
});
