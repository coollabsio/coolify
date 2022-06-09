import { dev } from '$app/env';
import { asyncExecShell, getDomain, getEngine } from '$lib/common';
import { supportedServiceTypesAndVersions } from '$lib/components/common';
import * as db from '$lib/database';
import { listServicesWithIncludes } from '$lib/database';
import { checkContainer } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

function configureMiddleware(
	{ id, container, port, domain, nakedDomain, isHttps, isWWW, isDualCerts, scriptName, type },
	traefik
) {
	if (isHttps) {
		traefik.http.routers[id] = {
			entrypoints: ['web'],
			rule: `Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`)`,
			service: `${id}`,
			middlewares: ['redirect-to-https']
		};

		traefik.http.services[id] = {
			loadbalancer: {
				servers: [
					{
						url: `http://${container}:${port}`
					}
				]
			}
		};

		if (isDualCerts) {
			traefik.http.routers[`${id}-secure`] = {
				entrypoints: ['websecure'],
				rule: `Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`)`,
				service: `${id}`,
				tls: {
					certresolver: 'letsencrypt'
				},
				middlewares: []
			};
		} else {
			if (isWWW) {
				traefik.http.routers[`${id}-secure-www`] = {
					entrypoints: ['websecure'],
					rule: `Host(\`www.${nakedDomain}\`)`,
					service: `${id}`,
					tls: {
						certresolver: 'letsencrypt'
					},
					middlewares: []
				};
				traefik.http.routers[`${id}-secure`] = {
					entrypoints: ['websecure'],
					rule: `Host(\`${nakedDomain}\`)`,
					service: `${id}`,
					tls: {
						domains: {
							main: `${domain}`
						}
					},
					middlewares: ['redirect-to-www']
				};
				traefik.http.routers[`${id}`].middlewares.push('redirect-to-www');
			} else {
				traefik.http.routers[`${id}-secure-www`] = {
					entrypoints: ['websecure'],
					rule: `Host(\`www.${nakedDomain}\`)`,
					service: `${id}`,
					tls: {
						domains: {
							main: `${domain}`
						}
					},
					middlewares: ['redirect-to-non-www']
				};
				traefik.http.routers[`${id}-secure`] = {
					entrypoints: ['websecure'],
					rule: `Host(\`${domain}\`)`,
					service: `${id}`,
					tls: {
						certresolver: 'letsencrypt'
					},
					middlewares: []
				};
				traefik.http.routers[`${id}`].middlewares.push('redirect-to-non-www');
			}
		}
	} else {
		traefik.http.routers[id] = {
			entrypoints: ['web'],
			rule: `Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`)`,
			service: `${id}`,
			middlewares: []
		};

		traefik.http.routers[`${id}-secure`] = {
			entrypoints: ['websecure'],
			rule: `Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`)`,
			service: `${id}`,
			tls: {
				domains: {
					main: `${nakedDomain}`
				}
			},
			middlewares: ['redirect-to-http']
		};

		traefik.http.services[id] = {
			loadbalancer: {
				servers: [
					{
						url: `http://${container}:${port}`
					}
				]
			}
		};

		if (!isDualCerts) {
			if (isWWW) {
				traefik.http.routers[`${id}`].middlewares.push('redirect-to-www');
				traefik.http.routers[`${id}-secure`].middlewares.push('redirect-to-www');
			} else {
				traefik.http.routers[`${id}`].middlewares.push('redirect-to-non-www');
				traefik.http.routers[`${id}-secure`].middlewares.push('redirect-to-non-www');
			}
		}
	}

	if (type === 'plausibleanalytics' && scriptName !== 'plausible.js') {
		if (!traefik.http.routers[`${id}`].middlewares.includes(`${id}-redir`)) {
			traefik.http.routers[`${id}`].middlewares.push(`${id}-redir`);
		}
		if (!traefik.http.routers[`${id}-secure`].middlewares.includes(`${id}-redir`)) {
			traefik.http.routers[`${id}-secure`].middlewares.push(`${id}-redir`);
		}
	}
}
export const get: RequestHandler = async (event) => {
	const traefik = {
		http: {
			routers: {},
			services: {},
			middlewares: {
				'redirect-to-https': {
					redirectscheme: {
						scheme: 'https'
					}
				},
				'redirect-to-http': {
					redirectscheme: {
						scheme: 'http'
					}
				},
				'redirect-to-non-www': {
					redirectregex: {
						regex: '^https?://www\\.(.+)',
						replacement: 'http://${1}'
					}
				},
				'redirect-to-www': {
					redirectregex: {
						regex: '^https?://(?:www\\.)?(.+)',
						replacement: 'http://www.${1}'
					}
				}
			}
		}
	};
	const applications = await db.prisma.application.findMany({
		include: { destinationDocker: true, settings: true }
	});
	const data = {
		applications: [],
		services: [],
		coolify: []
	};
	for (const application of applications) {
		const {
			fqdn,
			id,
			port,
			destinationDocker,
			destinationDockerId,
			settings: { previews, dualCerts }
		} = application;
		if (destinationDockerId) {
			const { engine, network } = destinationDocker;
			const isRunning = true;
			if (fqdn) {
				const domain = getDomain(fqdn);
				const nakedDomain = domain.replace(/^www\./, '');
				const isHttps = fqdn.startsWith('https://');
				const isWWW = fqdn.includes('www.');
				if (isRunning) {
					data.applications.push({
						id,
						container: id,
						port: port || 3000,
						domain,
						nakedDomain,
						isRunning,
						isHttps,
						isWWW,
						isDualCerts: dualCerts
					});
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
							const previewDomain = `${container.split('-')[1]}.${domain}`;
							const nakedDomain = previewDomain.replace(/^www\./, '');
							data.applications.push({
								id: container,
								container,
								port: port || 3000,
								domain: previewDomain,
								isRunning,
								nakedDomain,
								isHttps,
								isWWW,
								isDualCerts: dualCerts
							});
						}
					}
				}
			}
		}
	}
	const services = await listServicesWithIncludes();

	for (const service of services) {
		const {
			fqdn,
			id,
			type,
			destinationDocker,
			destinationDockerId,
			dualCerts,
			plausibleAnalytics
		} = service;
		if (destinationDockerId) {
			const { engine } = destinationDocker;
			const found = supportedServiceTypesAndVersions.find((a) => a.name === type);
			if (found) {
				const port = found.ports.main;
				const publicPort = service[type]?.publicPort;
				const isRunning = true;
				if (fqdn) {
					const domain = getDomain(fqdn);
					const nakedDomain = domain.replace(/^www\./, '');
					const isHttps = fqdn.startsWith('https://');
					const isWWW = fqdn.includes('www.');
					if (isRunning) {
						// Plausible Analytics custom script
						let scriptName = false;
						if (type === 'plausibleanalytics' && plausibleAnalytics.scriptName !== 'plausible.js') {
							scriptName = plausibleAnalytics.scriptName;
						}

						let container = id;
						let otherDomain = null;
						let otherNakedDomain = null;
						let otherIsHttps = null;
						let otherIsWWW = null;

						if (type === 'minio' && service.minio.apiFqdn) {
							otherDomain = getDomain(service.minio.apiFqdn);
							otherNakedDomain = otherDomain.replace(/^www\./, '');
							otherIsHttps = service.minio.apiFqdn.startsWith('https://');
							otherIsWWW = service.minio.apiFqdn.includes('www.');
						}
						data.services.push({
							id,
							container,
							type,
							otherDomain,
							otherNakedDomain,
							otherIsHttps,
							otherIsWWW,
							port,
							publicPort,
							domain,
							nakedDomain,
							isRunning,
							isHttps,
							isWWW,
							isDualCerts: dualCerts,
							scriptName
						});
					}
				}
			}
		}
	}

	const { fqdn, dualCerts } = await db.prisma.setting.findFirst();
	if (fqdn) {
		const domain = getDomain(fqdn);
		const nakedDomain = domain.replace(/^www\./, '');
		const isHttps = fqdn.startsWith('https://');
		const isWWW = fqdn.includes('www.');
		data.coolify.push({
			id: dev ? 'host.docker.internal' : 'coolify',
			container: dev ? 'host.docker.internal' : 'coolify',
			port: 3000,
			domain,
			nakedDomain,
			isHttps,
			isWWW,
			isDualCerts: dualCerts
		});
	}
	for (const application of data.applications) {
		configureMiddleware(application, traefik);
	}
	for (const service of data.services) {
		const { id, scriptName } = service;

		configureMiddleware(service, traefik);
		if (service.type === 'minio') {
			service.id = id + '-minio';
			service.container = id;
			service.domain = service.otherDomain;
			service.nakedDomain = service.otherNakedDomain;
			service.isHttps = service.otherIsHttps;
			service.isWWW = service.otherIsWWW;
			service.port = 9000;
			configureMiddleware(service, traefik);
		}

		if (scriptName) {
			traefik.http.middlewares[`${id}-redir`] = {
				replacepathregex: {
					regex: `/js/${scriptName}`,
					replacement: '/js/plausible.js'
				}
			};
		}
	}
	for (const coolify of data.coolify) {
		configureMiddleware(coolify, traefik);
	}
	if (Object.keys(traefik.http.routers).length === 0) {
		traefik.http.routers = null;
	}
	if (Object.keys(traefik.http.services).length === 0) {
		traefik.http.services = null;
	}
	return {
		status: 200,
		body: {
			...traefik
		}
	};
};
