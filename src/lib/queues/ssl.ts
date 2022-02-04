import { asyncExecShell, getDomain, getEngine } from '$lib/common';
import { prisma } from '$lib/database';
import { dockerInstance } from '$lib/docker';
import { forceSSLOnApplication } from '$lib/haproxy';
import * as db from '$lib/database';
import { dev } from '$app/env';

export default async function () {
	try {
		const destinationDockers = await prisma.destinationDocker.findMany({});
		for (const destination of destinationDockers) {
			if (destination.isCoolifyProxyUsed) {
				const docker = dockerInstance({ destinationDocker: destination });
				const containers = await docker.engine.listContainers();
				const configurations = containers.filter(
					(container) => container.Labels['coolify.managed']
				);
				for (const configuration of configurations) {
					const parsedConfiguration = JSON.parse(
						Buffer.from(configuration.Labels['coolify.configuration'], 'base64').toString()
					);
					if (configuration.Labels['coolify.type'] === 'standalone-application') {
						const { fqdn } = parsedConfiguration;
						if (fqdn) {
							const domain = getDomain(fqdn);
							const isHttps = fqdn.startsWith('https://');
							if (isHttps) {
								if (dev) {
									console.log('DEV MODE: SSL is enabled');
								} else {
									const host = getEngine(destination.engine);
									await asyncExecShell(
										`DOCKER_HOST=${host} docker run --rm --name certbot -p 9080:9080 -v "coolify-letsencrypt:/etc/letsencrypt" certbot/certbot --logs-dir /etc/letsencrypt/logs certonly --standalone --preferred-challenges http --http-01-address 0.0.0.0 --http-01-port 9080 -d ${domain} --agree-tos --non-interactive --register-unsafely-without-email`
									);
									const { stderr } = await asyncExecShell(
										`DOCKER_HOST=${host} docker run --rm --name bash -v "coolify-letsencrypt:/etc/letsencrypt" -v "coolify-ssl-certs:/app/ssl" alpine:latest cat /etc/letsencrypt/live/${domain}/fullchain.pem /etc/letsencrypt/live/${domain}/privkey.pem > /app/ssl/${domain}.pem`
									);
									if (stderr) throw new Error(stderr);
								}
							}
						}
					}
				}
			}
		}
		const { fqdn } = await db.listSettings();
		if (fqdn) {
			const domain = getDomain(fqdn);
			const isHttps = fqdn.startsWith('https://');
			if (isHttps) {
				if (dev) {
					console.log('DEV MODE: SSL is enabled');
				} else {
					await asyncExecShell(
						`docker run --rm --name certbot -p 9080:9080 -v "coolify-letsencrypt:/etc/letsencrypt" certbot/certbot --logs-dir /etc/letsencrypt/logs certonly --standalone --preferred-challenges http --http-01-address 0.0.0.0 --http-01-port 9080 -d ${domain} --agree-tos --non-interactive --register-unsafely-without-email`
					);

					const { stderr } = await asyncExecShell(
						`docker run --rm -v "coolify-letsencrypt:/etc/letsencrypt" -v "coolify-ssl-certs:/app/ssl" alpine:latest cat /etc/letsencrypt/live/${domain}/fullchain.pem /etc/letsencrypt/live/${domain}/privkey.pem > /app/ssl/${domain}.pem`
					);
					if (stderr) throw new Error(stderr);
				}
			}
		}
	} catch (error) {
		console.log(error);
		throw error;
	}
}
