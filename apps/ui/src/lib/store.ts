import { writable, readable, type Writable } from 'svelte/store';

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
interface AddToast {
    type?: "info" | "success" | "error",
    message: string,
    timeout?: number | undefined
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
        return location.set(newURL)
    } else if (CODESANDBOX_HOST){
		 const newURL = `https://${CODESANDBOX_HOST.replace(/\$PORT/,resource.exposePort)}`
         return location.set(newURL)
    }
    return location.set(resource.fqdn)
}

export const toasts: any = writable([])

export const dismissToast = (id: number) => {
  toasts.update((all: any) => all.filter((t: any) => t.id !== id))
}

export const addToast = (toast: AddToast) => {
  // Create a unique ID so we can easily find/remove it
  // if it is dismissible/has a timeout.
  const id = Math.floor(Math.random() * 10000)

  // Setup some sensible defaults for a toast.
  const defaults = {
    id,
    type: 'info',
    timeout: 2000,
  }

  // Push the toast to the top of the list of toasts
  const t = { ...defaults, ...toast }
  toasts.update((all: any) => [t, ...all])

  // If toast is dismissible, dismiss it after "timeout" amount of time.
  if (t.timeout) setTimeout(() => dismissToast(id), t.timeout)
}