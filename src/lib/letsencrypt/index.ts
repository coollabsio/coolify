import { asyncExecShell, getDomain, getEngine } from '$lib/common';
import { checkContainer, reloadHaproxy } from '$lib/haproxy';
import * as db from '$lib/database';
import { dev } from '$app/env';
import cuid from 'cuid';
import fs from 'fs/promises';
import getPort, { portNumbers } from 'get-port';
import { supportedServiceTypesAndVersions } from '$lib/components/common';

export async function letsEncrypt(domain, id = null, isCoolify = false) {
	try {
		const data = await db.prisma.setting.findFirst();
		const { minPort, maxPort } = data;

		const nakedDomain = domain.replace('www.', '');
		const wwwDomain = `www.${nakedDomain}`;
		const randomCuid = cuid();
		const randomPort = await getPort({ port: portNumbers(minPort, maxPort) });

		let host;
		let dualCerts = false;
		if (isCoolify) {
			dualCerts = data.dualCerts;
			host = 'unix:///var/run/docker.sock';
		} else {
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
		if (dualCerts) {
			let found = false;
			try {
				await asyncExecShell(
					`DOCKER_HOST=${host} docker run --rm -v "coolify-letsencrypt:/etc/letsencrypt" -v "coolify-ssl-certs:/app/ssl" alpine:latest sh -c "ls -1 /app/ssl/${wwwDomain}.pem"`
				);
				found = true;
			} catch (error) {
				//
			}
			if (found) return;

			await asyncExecShell(
				`DOCKER_HOST=${host} docker run --rm --name certbot-${randomCuid} -p 9080:${randomPort} -v "coolify-letsencrypt:/etc/letsencrypt" certbot/certbot --logs-dir /etc/letsencrypt/logs certonly --standalone --preferred-challenges http --http-01-address 0.0.0.0 --http-01-port ${randomPort} -d ${nakedDomain} -d ${wwwDomain} --expand --agree-tos --non-interactive --register-unsafely-without-email ${
					dev ? '--test-cert' : ''
				}`
			);
			await asyncExecShell(
				`DOCKER_HOST=${host} docker run --rm -v "coolify-letsencrypt:/etc/letsencrypt" -v "coolify-ssl-certs:/app/ssl" alpine:latest sh -c "test -d /etc/letsencrypt/live/${nakedDomain}/ && cat /etc/letsencrypt/live/${nakedDomain}/fullchain.pem /etc/letsencrypt/live/${nakedDomain}/privkey.pem > /app/ssl/${nakedDomain}.pem || cat /etc/letsencrypt/live/${wwwDomain}/fullchain.pem /etc/letsencrypt/live/${wwwDomain}/privkey.pem > /app/ssl/${wwwDomain}.pem"`
			);
			await reloadHaproxy(host);
		} else {
			let found = false;
			try {
				await asyncExecShell(
					`DOCKER_HOST=${host} docker run --rm -v "coolify-letsencrypt:/etc/letsencrypt" -v "coolify-ssl-certs:/app/ssl" alpine:latest sh -c "ls -1 /app/ssl/${domain}.pem"`
				);
				found = true;
			} catch (error) {
				//
			}
			if (found) return;
			await asyncExecShell(
				`DOCKER_HOST=${host} docker run --rm --name certbot-${randomCuid} -p 9080:${randomPort} -v "coolify-letsencrypt:/etc/letsencrypt" certbot/certbot --logs-dir /etc/letsencrypt/logs certonly --standalone --preferred-challenges http --http-01-address 0.0.0.0 --http-01-port ${randomPort} -d ${domain} --expand --agree-tos --non-interactive --register-unsafely-without-email ${
					dev ? '--test-cert' : ''
				}`
			);
			await asyncExecShell(
				`DOCKER_HOST=${host} docker run --rm -v "coolify-letsencrypt:/etc/letsencrypt" -v "coolify-ssl-certs:/app/ssl" alpine:latest sh -c "cat /etc/letsencrypt/live/${domain}/fullchain.pem /etc/letsencrypt/live/${domain}/privkey.pem > /app/ssl/${domain}.pem"`
			);
			await reloadHaproxy(host);
		}
	} catch (error) {
		if (error.code !== 0) {
			throw error;
		}
	}
}

export async function generateSSLCerts() {
	const ssls = [];
	const applications = await db.prisma.application.findMany({
		include: { destinationDocker: true, settings: true },
		orderBy: { createdAt: 'desc' }
	});
	for (const application of applications) {
		try {
			if (application.fqdn && application.destinationDockerId) {
				const {
					fqdn,
					id,
					destinationDocker: { engine, network },
					settings: { previews }
				} = application;
				const isRunning = await checkContainer(engine, id);
				const domain = getDomain(fqdn);
				const isHttps = fqdn.startsWith('https://');
				if (isRunning) {
					if (isHttps) ssls.push({ domain, id, isCoolify: false });
				}
				if (previews) {
					const host = getEngine(engine);
					const { stdout } = await asyncExecShell(
						`DOCKER_HOST=${host} docker container ls --filter="status=running" --filter="network=${network}" --filter="name=${id}-" --format="{{json .Names}}"`
					);
					const containers = stdout
						.trim()
						.split('\n')
						.filter((a) => a)
						.map((c) => c.replace(/"/g, ''));
					if (containers.length > 0) {
						for (const container of containers) {
							let previewDomain = `${container.split('-')[1]}.${domain}`;
							if (isHttps) ssls.push({ domain: previewDomain, id, isCoolify: false });
						}
					}
				}
			}
		} catch (error) {
			console.log(`Error during generateSSLCerts with ${application.fqdn}: ${error}`);
		}
	}
	const services = await db.prisma.service.findMany({
		include: {
			destinationDocker: true,
			minio: true,
			plausibleAnalytics: true,
			vscodeserver: true,
			wordpress: true,
			ghost: true
		},
		orderBy: { createdAt: 'desc' }
	});

	for (const service of services) {
		try {
			if (service.fqdn && service.destinationDockerId) {
				const {
					fqdn,
					id,
					type,
					destinationDocker: { engine }
				} = service;
				const found = supportedServiceTypesAndVersions.find((a) => a.name === type);
				if (found) {
					const domain = getDomain(fqdn);
					const isHttps = fqdn.startsWith('https://');
					const isRunning = await checkContainer(engine, id);
					if (isRunning) {
						if (isHttps) ssls.push({ domain, id, isCoolify: false });
					}
				}
			}
		} catch (error) {
			console.log(`Error during generateSSLCerts with ${service.fqdn}: ${error}`);
		}
	}
	const { fqdn } = await db.prisma.setting.findFirst();
	if (fqdn) {
		const domain = getDomain(fqdn);
		const isHttps = fqdn.startsWith('https://');
		if (isHttps) ssls.push({ domain, id: 'coolify', isCoolify: true });
	}
	if (ssls.length > 0) {
		const sslDir = dev ? '/tmp/ssl' : '/app/ssl';
		if (dev) {
			try {
				await asyncExecShell(`mkdir -p ${sslDir}`);
			} catch (error) {
				//
			}
		}
		const files = await fs.readdir(sslDir);
		let certificates = [];
		if (files.length > 0) {
			for (const file of files) {
				file.endsWith('.pem') && certificates.push(file.replace(/\.pem$/, ''));
			}
		}
		for (const ssl of ssls) {
			if (!dev) {
				if (
					certificates.includes(ssl.domain) ||
					certificates.includes(ssl.domain.replace('www.', ''))
				) {
					console.log(`Certificate for ${ssl.domain} already exists`);
				} else {
					console.log('Generating SSL for', ssl.domain);
					await letsEncrypt(ssl.domain, ssl.id, ssl.isCoolify);
				}
			} else {
				if (
					certificates.includes(ssl.domain) ||
					certificates.includes(ssl.domain.replace('www.', ''))
				) {
					console.log(`Certificate for ${ssl.domain} already exists`);
				} else {
					console.log('Generating SSL for', ssl.domain);
				}
			}
		}
	}
}
