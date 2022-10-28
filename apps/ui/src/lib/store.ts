import { dev } from '$app/env';
import cuid from 'cuid';
import Cookies from 'js-cookie';
import {getAPIUrl} from '$lib/api';
import {getDomain} from '$lib/common'
import { writable, readable, type Writable } from 'svelte/store';

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
    pendingInvitations: Array<any>
}
interface AddToast {
    type?: "info" | "success" | "error",
    message: string,
    timeout?: number | undefined
}
export const updateLoading: Writable<boolean> = writable(false);
export const isUpdateAvailable: Writable<boolean> = writable(false);
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
    pendingInvitations: []
});
export const disabledButton: Writable<boolean> = writable(false);
export const isDeploymentEnabled: Writable<boolean> = writable(false);
export function checkIfDeploymentEnabledApplications(isAdmin: boolean, application: any) {
    return (
        isAdmin &&
        (application.buildPack === 'compose') ||
        (application.fqdn || application.settings.isBot) &&
        application.gitSource &&
        application.repository &&
        application.destinationDocker &&
        application.buildPack
    );
}
export function checkIfDeploymentEnabledServices(isAdmin: boolean, service: any) {
    return (
        isAdmin &&
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
export const connect = () => {
    const token = Cookies.get('token')
    if (token) {
        let url = `wss://${window.location.hostname}/realtime`
        if (dev) {
            const apiUrl = getDomain(getAPIUrl())
            url = `ws://${apiUrl}/realtime`
        }
        const ws = new WebSocket(url);
        ws.addEventListener("message", (message: any) => {
            appSession.subscribe((session: any) => {
                const data: Request = { ...JSON.parse(message.data), timestamp: message.timeStamp };
                if (data.teamId === session.teamId) {
                    if (data.type === 'service') {
                        const ending = data.message === "ending"
                        status.update((status: any) => ({
                            ...status,
                            service: {
                                ...status.service,
                                startup: {
                                    ...status.service.startup,
                                    ...(ending ? { [data.id]: undefined } : { [data.id]: data.message })
                                }
                            }
                        }))

                    }
                }
            })

        });
        ws.addEventListener('open', (event) => {
            ws.send(JSON.stringify({ type: 'subscribe', message: 'ping' }))
        });
        ws.addEventListener('error', (event) => {
            console.log('error with ws');
            console.log(event)
        });
        ws.addEventListener('close', (event) => {
            if (!ws || ws.readyState == 3) {
                setTimeout(() => {
                    connect()
                }, 1000)
            }
        });
    }
};