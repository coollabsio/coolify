import { dev } from '$app/env';
import { forceSSLOffApplication, forceSSLOnApplication, getNextTransactionId } from '$lib/haproxy';
import { asyncExecShell, getEngine } from './common';
import * as db from '$lib/database';

export async function letsEncrypt({ domain, isCoolify = false, id = null }) {
	try {
		if (dev) {
			return await forceSSLOnApplication({ domain });
		} else {
			if (isCoolify) {
				await asyncExecShell(
					`docker run --rm --name certbot -p 9080:9080 -v "coolify-letsencrypt:/etc/letsencrypt" certbot/certbot --logs-dir /etc/letsencrypt/logs certonly --standalone --preferred-challenges http --http-01-address 0.0.0.0 --http-01-port 9080 -d ${domain} --agree-tos --non-interactive --register-unsafely-without-email`
				);

				const { stderr } = await asyncExecShell(
					`docker run --rm -v "coolify-letsencrypt:/etc/letsencrypt" -v "coolify-ssl-certs:/app/ssl" alpine:latest cat /etc/letsencrypt/live/${domain}/fullchain.pem /etc/letsencrypt/live/${domain}/privkey.pem > /app/ssl/${domain}.pem`
				);
				if (stderr) throw new Error(stderr);
				return;
			}
			const { destinationDocker, destinationDockerId } = await db.prisma.application.findUnique({
				where: { id },
				include: { destinationDocker: true }
			});
			// Set SSL with Let's encrypt
			if (destinationDockerId && destinationDocker) {
				const host = getEngine(destinationDocker.engine);
				await asyncExecShell(
					`DOCKER_HOST=${host} docker run --rm --name certbot -p 9080:9080 -v "coolify-letsencrypt:/etc/letsencrypt" certbot/certbot --logs-dir /etc/letsencrypt/logs certonly --standalone --preferred-challenges http --http-01-address 0.0.0.0 --http-01-port 9080 -d ${domain} --agree-tos --non-interactive --register-unsafely-without-email`
				);
				const { stderr } = await asyncExecShell(
					`DOCKER_HOST=${host} docker run --rm --name bash -v "coolify-letsencrypt:/etc/letsencrypt" -v "coolify-ssl-certs:/app/ssl" alpine:latest cat /etc/letsencrypt/live/${domain}/fullchain.pem /etc/letsencrypt/live/${domain}/privkey.pem > /app/ssl/${domain}.pem`
				);
				if (stderr) throw new Error(stderr);
				await forceSSLOnApplication({ domain });
			}
		}
	} catch (error) {
		throw error;
	}
}
