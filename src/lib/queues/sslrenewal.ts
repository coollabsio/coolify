import { asyncExecShell } from '$lib/common';
import { reloadHaproxy } from '$lib/haproxy';

export default async function () {
	try {
		await asyncExecShell(
			`docker run --rm --name certbot-renewal -v "coolify-letsencrypt:/etc/letsencrypt" certbot/certbot --logs-dir /etc/letsencrypt/logs renew`
		);
		await reloadHaproxy('unix:///var/run/docker.sock');
	} catch (error) {
		throw error;
	}
}
