import { dev } from '$app/env';
import { forceSSLOnApplication } from '$lib/haproxy';
import { asyncExecShell, getEngine } from './common';
import * as db from '$lib/database';
import cuid from 'cuid';

export async function letsEncrypt({ domain, isCoolify = false, id = null }) {
	try {
		const nakedDomain = domain.replace('www.', '');
		const wwwDomain = `www.${nakedDomain}`;
		const randomCuid = cuid();
		if (dev) {
			return await forceSSLOnApplication({ domain });
		} else {
			if (isCoolify) {
				await asyncExecShell(
					`docker run --rm --name certbot-${randomCuid} -p 9080:9080 -v "coolify-letsencrypt:/etc/letsencrypt" certbot/certbot --logs-dir /etc/letsencrypt/logs certonly --standalone --preferred-challenges http --http-01-address 0.0.0.0 --http-01-port 9080 -d ${nakedDomain} -d ${wwwDomain} --expand --agree-tos --non-interactive --register-unsafely-without-email`
				);

				const { stderr: copyError } = await asyncExecShell(
					`docker run --rm -v "coolify-letsencrypt:/etc/letsencrypt" -v "coolify-ssl-certs:/app/ssl" alpine:latest sh -c "test -d /etc/letsencrypt/live/${nakedDomain}/ && cat /etc/letsencrypt/live/${nakedDomain}/fullchain.pem /etc/letsencrypt/live/${nakedDomain}/privkey.pem > /app/ssl/${nakedDomain}.pem || cat /etc/letsencrypt/live/${wwwDomain}/fullchain.pem /etc/letsencrypt/live/${wwwDomain}/privkey.pem > /app/ssl/${wwwDomain}.pem"`
				);

				if (copyError) throw copyError;
				return;
			}
			let data: any = await db.prisma.application.findUnique({
				where: { id },
				include: { destinationDocker: true }
			});
			if (!data) {
				data = await db.prisma.service.findUnique({
					where: { id },
					include: { destinationDocker: true }
				});
			}
			// Set SSL with Let's encrypt
			if (data.destinationDockerId && data.destinationDocker) {
				const host = getEngine(data.destinationDocker.engine);
				await asyncExecShell(
					`DOCKER_HOST=${host} docker run --rm --name certbot-${randomCuid} -p 9080:9080 -v "coolify-letsencrypt:/etc/letsencrypt" certbot/certbot --logs-dir /etc/letsencrypt/logs certonly --standalone --preferred-challenges http --http-01-address 0.0.0.0 --http-01-port 9080 -d ${nakedDomain} -d ${wwwDomain} --expand --agree-tos --non-interactive --register-unsafely-without-email`
				);
				const { stderr: copyError } = await asyncExecShell(
					`DOCKER_HOST=${host} docker run --rm -v "coolify-letsencrypt:/etc/letsencrypt" -v "coolify-ssl-certs:/app/ssl" alpine:latest sh -c "test -d /etc/letsencrypt/live/${nakedDomain}/ && cat /etc/letsencrypt/live/${nakedDomain}/fullchain.pem /etc/letsencrypt/live/${nakedDomain}/privkey.pem > /app/ssl/${nakedDomain}.pem || cat /etc/letsencrypt/live/${wwwDomain}/fullchain.pem /etc/letsencrypt/live/${wwwDomain}/privkey.pem > /app/ssl/${wwwDomain}.pem"`
				);
				if (copyError) throw copyError;
				await forceSSLOnApplication({ domain });
			}
		}
	} catch (error) {
		console.log(error);
		throw error;
	}
}
