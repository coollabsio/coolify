import { dev } from '$app/env';
import { forceSSLOnApplication } from '$lib/haproxy';
import { asyncExecShell, getEngine } from './common';
import * as db from '$lib/database';
import cuid from 'cuid';
import getPort from 'get-port';

export async function letsEncrypt({ domain, isCoolify = false, id = null }) {
	try {
		const nakedDomain = domain.replace('www.', '');
		const wwwDomain = `www.${nakedDomain}`;
		const randomCuid = cuid();
		const randomPort = await getPort();

		let host;
		let dualCerts = false;
		if (isCoolify) {
			const data = await db.prisma.setting.findFirst();
			dualCerts = data.dualCerts;
			host = '/var/run/docker.sock';
		} else {
			// Check Application
			const applicationData = await db.prisma.application.findUnique({
				where: { id },
				include: { destinationDocker: true, settings: true }
			});
			if (applicationData) {
				if (applicationData?.destinationDockerId && applicationData?.destinationDocker) {
					host = getEngine(applicationData.destinationDocker.engine);
				}
				if (applicationData?.settings?.dualCerts) {
					dualCerts = applicationData.settings.dualCerts;
				}
			}
			// Check Service
			const serviceData = await db.prisma.service.findUnique({
				where: { id },
				include: { destinationDocker: true }
			});
			if (serviceData) {
				if (serviceData?.destinationDockerId && serviceData?.destinationDocker) {
					host = getEngine(serviceData.destinationDocker.engine);
				}
				if (serviceData?.dualCerts) {
					dualCerts = serviceData.dualCerts;
				}
			}
		}
		if (!dev) {
			if (dualCerts) {
				await asyncExecShell(
					`DOCKER_HOST=${host} docker run --rm --name certbot-${randomCuid} -p ${randomPort}:${randomPort} -v "coolify-letsencrypt:/etc/letsencrypt" certbot/certbot --logs-dir /etc/letsencrypt/logs certonly --standalone --preferred-challenges http --http-01-address 0.0.0.0 --http-01-port ${randomPort} -d ${nakedDomain} -d ${wwwDomain} --expand --agree-tos --non-interactive --register-unsafely-without-email`
				);
				await asyncExecShell(
					`DOCKER_HOST=${host} docker run --rm -v "coolify-letsencrypt:/etc/letsencrypt" -v "coolify-ssl-certs:/app/ssl" alpine:latest sh -c "test -d /etc/letsencrypt/live/${nakedDomain}/ && cat /etc/letsencrypt/live/${nakedDomain}/fullchain.pem /etc/letsencrypt/live/${nakedDomain}/privkey.pem > /app/ssl/${nakedDomain}.pem || cat /etc/letsencrypt/live/${wwwDomain}/fullchain.pem /etc/letsencrypt/live/${wwwDomain}/privkey.pem > /app/ssl/${wwwDomain}.pem"`
				);
			} else {
				await asyncExecShell(
					`DOCKER_HOST=${host} docker run --rm --name certbot-${randomCuid} -p ${randomPort}:${randomPort} -v "coolify-letsencrypt:/etc/letsencrypt" certbot/certbot --logs-dir /etc/letsencrypt/logs certonly --standalone --preferred-challenges http --http-01-address 0.0.0.0 --http-01-port ${randomPort} -d ${domain}  --expand --agree-tos --non-interactive --register-unsafely-without-email`
				);
				await asyncExecShell(
					`DOCKER_HOST=${host} docker run --rm -v "coolify-letsencrypt:/etc/letsencrypt" -v "coolify-ssl-certs:/app/ssl" alpine:latest sh -c "cat /etc/letsencrypt/live/${domain}/fullchain.pem /etc/letsencrypt/live/${domain}/privkey.pem > /app/ssl/${domain}.pem"`
				);
			}
		} else {
			console.log({ dualCerts, host, wwwDomain, nakedDomain, domain });
		}
		if (!isCoolify) {
			await forceSSLOnApplication({ domain });
		}
	} catch (error) {
		console.log(error);
		throw error;
	}
}
