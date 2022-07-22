import { writable, readable, type Writable, type Readable } from 'svelte/store';

interface AppSession {
    ipv4: string | null,
    ipv6: string | null,
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
    ipv4: null,
    ipv6: null,
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
        isRunning: false,
        isExited: false,
        loading: false,
        initialLoading: true
    },
    database: {
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

export const location: Writable<null | string> = writable(null)
export const setLocation = (resource: any) => {
    if (GITPOD_WORKSPACE_URL && resource.exposePort) {
        const { href } = new URL(GITPOD_WORKSPACE_URL);
        const newURL = href
            .replace('https://', `https://${resource.exposePort}-`)
            .replace(/\/$/, '');
        location.set(newURL)
    } else {
        location.set(resource.fqdn)
    }
}