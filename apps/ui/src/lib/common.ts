import { addToast } from '$lib/store';

export const asyncSleep = (delay: number) =>
	new Promise((resolve) => setTimeout(resolve, delay));

export let initials = (str:string) => (str||'').split(' ').map( (wrd) => wrd[0]).join('')

export function errorNotification(error: any | { message: string }): void {
	if (error.message) {
		if (error.message === 'Cannot read properties of undefined (reading \'postMessage\')') {
			return addToast({
				message: 'Currently there is background process in progress. Please try again later.',
				type: 'error',
			});
		}
		addToast({
			message: error.message,
			type: 'error',
		});
	} else {
		addToast({
			message: 'Ooops, something is not okay, are you okay?',
			type: 'error',
		});
	}
	console.error(JSON.stringify(error));
}

export function getDomain(domain: string) {
	return domain?.replace('https://', '').replace('http://', '');
}
export function dashify(str: string, options?: any): string {
	if (typeof str !== 'string') return str;
	return str
		.trim()
		.replace(/\W/g, (m) => (/[À-ž]/.test(m) ? m : '-'))
		.replace(/^-+|-+$/g, '')
		.replace(/-{2,}/g, (m) => (options && options.condense ? '-' : m))
		.toLowerCase();
}

export const dateOptions: any = {
	year: 'numeric',
	month: 'short',
	day: '2-digit',
	hour: 'numeric',
	minute: 'numeric',
	second: 'numeric',
	hour12: false
};

export const staticDeployments = [
	'react',
	'vuejs',
	'static',
	'svelte',
	'gatsby',
	'php',
	'astro',
	'eleventy'
];
export const notNodeDeployments = ['php', 'docker', 'rust', 'python', 'deno', 'laravel', 'heroku'];


export function generateRemoteEngine(destination: any) {
	return `ssh://${destination.user}@${destination.ipAddress}:${destination.port}`;
}

export function changeQueryParams(buildId: string) {
	const queryParams = new URLSearchParams(window.location.search);
	queryParams.set('buildId', buildId);
	// @ts-ignore
	return history.pushState(null, null, '?' + queryParams.toString());
}


export function handlerNotFoundLoad(error: any, url: URL) {
	if (error?.status === 404) {
		return {
			status: 302,
			redirect: '/'
		};
	}
	return {
		status: 500,
		error: new Error(`Could not load ${url}`)
	};
}

export function getRndInteger(min: number, max: number) {
	return Math.floor(Math.random() * (max - min + 1)) + min;
}
