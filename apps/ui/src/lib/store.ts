import { browser } from '$app/env';
import { writable, readable, type Writable, type Readable } from 'svelte/store';
// import { version as currentVersion } from '../../package.json';
interface AppSession {
    version: string
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
    version: '3.0.0',
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
    }
});

export const features = readable({
    beta: window.localStorage.getItem('beta') === 'true',
    latestVersion: window.localStorage.getItem('latestVersion')
});
