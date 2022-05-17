import { dev } from '$app/env';
import { asyncExecShell, getDomain, getEngine } from '$lib/common';
import { supportedServiceTypesAndVersions } from '$lib/components/common';
import * as db from '$lib/database';
import { listServicesWithIncludes } from '$lib/database';
import { checkContainer } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

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

function configureMiddleware({ id, port, domain, nakedDomain, isHttps, isWWW, isDualCerts }) {
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
						url: `http://${id}:${port}`
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
						url: `http://${id}:${port}`
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
}
export const get: RequestHandler = async (event) => {
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
			settings: { previews, dualCerts },
			updatedAt
		} = application;
		if (destinationDockerId) {
			const { engine, network } = destinationDocker;
			const isRunning = await checkContainer(engine, id);
			if (fqdn) {
				const domain = getDomain(fqdn);
				const nakedDomain = domain.replace(/^www\./, '');
				const isHttps = fqdn.startsWith('https://');
				const isWWW = fqdn.includes('www.');
				if (isRunning) {
					data.applications.push({
						id,
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
							data.applications.push({
								id: container,
								port: port || 3000,
								domain: previewDomain,
								isRunning,
								isHttps,
								redirectTo: isWWW ? previewDomain.replace('www.', '') : 'www.' + previewDomain,
								updatedAt: updatedAt.getTime()
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
			updatedAt,
			dualCerts,
			plausibleAnalytics
		} = service;
		if (destinationDockerId) {
			const { engine } = destinationDocker;
			const found = supportedServiceTypesAndVersions.find((a) => a.name === type);
			if (found) {
				const port = found.ports.main;
				const publicPort = service[type]?.publicPort;
				const isRunning = await checkContainer(engine, id);
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

						data.services.push({
							id,
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
			port: 3000,
			domain,
			nakedDomain,
			isHttps,
			isWWW,
			isDualCerts: dualCerts
		});
	}
	for (const application of data.applications) {
		configureMiddleware(application);
	}
	for (const service of data.services) {
		const { id, scriptName } = service;
		configureMiddleware(service);

		if (scriptName) {
			traefik.http.middlewares[`${id}-redir`] = {
				replacepathregex: {
					regex: `/js/${scriptName}`,
					replacement: '/js/plausible.js'
				}
			};
			if (traefik.http.routers[id].middlewares.length > 0) {
				traefik.http.routers[id].middlewares.push(`${id}-redir`);
			} else {
				traefik.http.routers[id].middlewares = [`${id}-redir`];
			}
		}
	}
	for (const coolify of data.coolify) {
		configureMiddleware(coolify);
	}

	return {
		status: 200,
		body: {
			...traefik
		}
	};
};
