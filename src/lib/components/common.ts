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

export const staticDeployments = ['react', 'vuejs', 'static', 'svelte', 'gatsby', 'php'];
export const notNodeDeployments = ['php', 'docker', 'rust'];

export function getDomain(domain) {
	return domain?.replace('https://', '').replace('http://', '');
}
