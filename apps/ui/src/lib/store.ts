import { dev } from '$app/env';
import cuid from 'cuid';
import Cookies from 'js-cookie';
import { writable, readable, type Writable } from 'svelte/store';
import { io as ioClient } from 'socket.io-client';
const socket = ioClient(dev ? `http://${window.location.hostname}:3001` : '/', { auth: { token: Cookies.get('token') }, autoConnect: false });

export const io = socket;
interface AppSession {
    isRegistrationEnabled: boolean;
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
    },
    pendingInvitations: Array<any>,
    isARM: boolean
}
interface AddToast {
    type?: "info" | "success" | "error",
    message: string,
    timeout?: number | undefined
}
export const updateLoading: Writable<boolean> = writable(false);
export const isUpdateAvailable: Writable<boolean> = writable(false);
export const latestVersion: Writable<string> = writable('latest');
export const search: any = writable('')
export const loginEmail: Writable<string | undefined> = writable()
export const appSession: Writable<AppSession> = writable({
    isRegistrationEnabled: false,
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
    },
    pendingInvitations: [],
    isARM: false
});
export const disabledButton: Writable<boolean> = writable(false);
export const isDeploymentEnabled: Writable<boolean> = writable(false);
export function checkIfDeploymentEnabledApplications(application: any) {
    return !!(
        (application.buildPack === 'compose') ||
        (application.fqdn || application.settings?.isBot) &&
        ((application.gitSource &&
            application.repository &&
            application.buildPack) || application.simpleDockerfile) &&
        application.destinationDocker

    );
}
export function checkIfDeploymentEnabledServices( service: any) {
    return (
        service.fqdn &&
        service.destinationDocker &&
        service.version &&
        service.type
    );
}
export const status: Writable<any> = writable({
    application: {
        statuses: [],
        overallStatus: 'stopped',
        loading: false,
        restarting: false,
        initialLoading: true
    },
    service: {
        statuses: [],
        overallStatus: 'stopped',
        loading: false,
        startup: {},
        initialLoading: true
    },
    database: {
        isRunning: false,
        isExited: false,
        loading: false,
        initialLoading: true,
        isPublic: false
    }

});

export const features = readable({
    beta: window.localStorage.getItem('beta') === 'true',
    latestVersion: window.localStorage.getItem('latestVersion')
});

export const location: Writable<null | string> = writable(null)
export const setLocation = (resource: any, settings?: any) => {
    if (resource.settings.isBot && resource.exposePort) {
        disabledButton.set(false);
        return location.set(`http://${dev ? 'localhost' : settings.ipv4}:${resource.exposePort}`)
    }
    if (GITPOD_WORKSPACE_URL && resource.exposePort) {
        const { href } = new URL(GITPOD_WORKSPACE_URL);
        const newURL = href
            .replace('https://', `https://${resource.exposePort}-`)
            .replace(/\/$/, '');
        return location.set(newURL)
    } else if (CODESANDBOX_HOST) {
        const newURL = `https://${CODESANDBOX_HOST.replace(/\$PORT/, resource.exposePort)}`
        return location.set(newURL)
    }
    if (resource.fqdn) {
        return location.set(resource.fqdn)
    } else {
        location.set(null);
        disabledButton.set(false);
    }
}

export const toasts: any = writable([])

export const dismissToast = (id: string) => {
    toasts.update((all: any) => all.filter((t: any) => t.id !== id))
}
export const pauseToast = (id: string) => {
    toasts.update((all: any) => {
        const index = all.findIndex((t: any) => t.id === id);
        if (index > -1) clearTimeout(all[index].timeoutInterval);
        return all;
    })
}
export const resumeToast = (id: string) => {
    toasts.update((all: any) => {
        const index = all.findIndex((t: any) => t.id === id);
        if (index > -1) {
            all[index].timeoutInterval = setTimeout(() => {
                dismissToast(id)
            }, all[index].timeout)
        }
        return all;
    })
}

export const addToast = (toast: AddToast) => {
    const id = cuid();
    const defaults = {
        id,
        type: 'info',
        timeout: 2000,
    }
    let t: any = { ...defaults, ...toast }
    if (t.timeout) t.timeoutInterval = setTimeout(() => dismissToast(id), t.timeout)
    toasts.update((all: any) => [t, ...all])
}

export const selectedBuildId: any = writable(null)

type State = {
    requests: Array<Request>;
};
export const state = writable<State>({
    requests: [],
});
