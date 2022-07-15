import { toast } from '@zerodevx/svelte-toast';
import { supportedServiceTypesAndVersions } from 'shared/index';
export const asyncSleep = (delay: number) =>
	new Promise((resolve) => setTimeout(resolve, delay));

export function errorNotification(error: any): void {
	if (error.message) {
		if (error.message === 'Cannot read properties of undefined (reading \'postMessage\')') {
			toast.push('Currently there is background process in progress. Please try again later.');
			return;
		}
		toast.push(error.message);
	} else {
		toast.push('Ooops, something is not okay, are you okay?');
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
export const notNodeDeployments = ['php', 'docker', 'rust', 'python', 'deno', 'laravel'];


export function generateRemoteEngine(destination: any) {
	return `ssh://${destination.user}@${destination.ipAddress}:${destination.port}`;
}

export function changeQueryParams(buildId: string) {
	const queryParams = new URLSearchParams(window.location.search);
	queryParams.set('buildId', buildId);
	// @ts-ignore
	return history.pushState(null, null, '?' + queryParams.toString());
}

export const supportedDatabaseTypesAndVersions = [
	{
		name: 'mongodb',
		fancyName: 'MongoDB',
		baseImage: 'bitnami/mongodb',
		versions: ['5.0', '4.4', '4.2']
	},
	{ name: 'mysql', fancyName: 'MySQL', baseImage: 'bitnami/mysql', versions: ['8.0', '5.7'] },
	{
		name: 'mariadb',
		fancyName: 'MariaDB',
		baseImage: 'bitnami/mariadb',
		versions: ['10.7', '10.6', '10.5', '10.4', '10.3', '10.2']
	},
	{
		name: 'postgresql',
		fancyName: 'PostgreSQL',
		baseImage: 'bitnami/postgresql',
		versions: ['14.2.0', '13.6.0', '12.10.0	', '11.15.0', '10.20.0']
	},
	{
		name: 'redis',
		fancyName: 'Redis',
		baseImage: 'bitnami/redis',
		versions: ['6.2', '6.0', '5.0']
	},
	{ name: 'couchdb', fancyName: 'CouchDB', baseImage: 'bitnami/couchdb', versions: ['3.2.1'] }
];

export const getServiceMainPort = (service: string) => {
	const serviceType = supportedServiceTypesAndVersions.find((s) => s.name === service);
	if (serviceType) {
		return serviceType.ports.main;
	}
	return null;
};



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