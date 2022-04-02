export const asyncSleep = (delay) => new Promise((resolve) => setTimeout(resolve, delay));
export const dateOptions: DateTimeFormatOptions = {
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
export const notNodeDeployments = ['php', 'docker', 'rust', 'python'];

export function getDomain(domain) {
	return domain?.replace('https://', '').replace('http://', '');
}
export function generateRemoteEngine(destination) {
	return `ssh://${destination.user}@${destination.ipAddress}:${destination.port}`;
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
