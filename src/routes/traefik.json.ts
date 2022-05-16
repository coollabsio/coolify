import { dev } from '$app/env';
import { asyncExecShell, getDomain, getEngine } from '$lib/common';
import { supportedServiceTypesAndVersions } from '$lib/components/common';
import * as db from '$lib/database';
import { listServicesWithIncludes } from '$lib/database';
import { checkContainer } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

function generateMiddleware({ id, isDualCerts, isWWW, isHttps, traefik }) {
	if (!isDualCerts) {
		if (isWWW) {
			if (isHttps) {
				traefik.http.routers[id].middlewares?.length > 0
					? traefik.http.routers[id].middlewares.push('https-redirect-non-www-to-www')
					: (traefik.http.routers[id].middlewares = [
							'https-redirect-non-www-to-www',
							'http-to-https'
					  ]);
			} else {
				traefik.http.routers[id].middlewares?.length > 0
					? traefik.http.routers[id].middlewares.push('http-redirect-non-www-to-www')
					: (traefik.http.routers[id].middlewares = [
							'http-redirect-non-www-to-www',
							'https-to-http'
					  ]);
			}
		} else {
			if (isHttps) {
				traefik.http.routers[id].middlewares?.length > 0
					? traefik.http.routers[id].middlewares.push('https-redirect-www-to-non-www')
					: (traefik.http.routers[id].middlewares = [
							'https-redirect-www-to-non-www',
							'http-to-https'
					  ]);
			} else {
				traefik.http.routers[id]?.middlewares?.length > 0
					? traefik.http.routers[id].middlewares.push('http-redirect-www-to-non-www')
					: (traefik.http.routers[id].middlewares = ['http-redirect-www-to-non-www']);
			}
		}
	}
}
export const get: RequestHandler = async (event) => {
	const id = event.url.searchParams.get('id');
	if (id) {
		const privatePort = event.url.searchParams.get('privatePort');
		const publicPort = event.url.searchParams.get('publicPort');
		const type = event.url.searchParams.get('type');
		if (publicPort) {
			if (type === 'tcp') {
				const traefik = {
					[type]: {
						routers: {
							[id]: {
								entrypoints: [type],
								rule: `HostSNI(\`*\`)`,
								service: id
							}
						},
						services: {
							[id]: {
								loadbalancer: {
									servers: [{ address: `${id}:${privatePort}` }]
								}
							}
						},
						middlewares: {
							['global-compress']: {
								compress: true
							}
						}
					}
				};
				return {
					status: 200,
					body: {
						...traefik
					}
				};
			} else if (type === 'http') {
				const service = await db.prisma.service.findFirst({ where: { id } });
				if (service?.fqdn) {
					const domain = getDomain(service.fqdn);
					const isWWW = domain.startsWith('www.');
					const traefik = {
						[type]: {
							routers: {
								[id]: {
									entrypoints: [type],
									rule: isWWW
										? `Host(\`${domain}\`) || Host(\`www.${domain}\`)`
										: `Host(\`${domain}\`)`,
									service: id
								}
							},
							services: {
								[id]: {
									loadbalancer: {
										servers: [{ url: `http://${id}:${privatePort}` }]
									}
								}
							},
							middlewares: {
								['global-compress']: {
									compress: true
								}
							}
						}
					};
					return {
						status: 200,
						body: {
							...traefik
						}
					};
				}
			}
		}
		return {
			status: 500
		};
	} else {
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
									isWWW
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
				dualCerts,
				destinationDocker,
				destinationDockerId,
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
							if (
								type === 'plausibleanalytics' &&
								plausibleAnalytics.scriptName !== 'plausible.js'
							) {
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
		const traefik = {
			http: {
				routers: {},
				services: {},
				middlewares: {
					['global-compress']: {
						compress: true
					},
					['https-redirect-non-www-to-www']: {
						redirectregex: {
							regex: '^https://(?:www\\.)?(.+)',
							replacement: 'https://www.${1}',
							permanent: dev ? false : true
						}
					},
					['http-redirect-non-www-to-www']: {
						redirectregex: {
							regex: '^http://(?:www\\.)?(.+)',
							replacement: 'http://www.${1}',
							permanent: dev ? false : true
						}
					},
					['https-redirect-www-to-non-www']: {
						redirectregex: {
							regex: '^https?://www\\.(.+)',
							replacement: 'https://${1}',
							permanent: dev ? false : true
						}
					},
					['http-redirect-www-to-non-www']: {
						redirectregex: {
							regex: '^http?://www\\.(.+)',
							replacement: 'http://${1}',
							permanent: dev ? false : true
						}
					},
					['http-to-https']: {
						redirectregex: {
							regex: '^http?://(.+)',
							replacement: 'https://${1}',
							permanent: dev ? false : true
						}
					},
					['https-to-http']: {
						redirectregex: {
							regex: '^https?://(.+)',
							replacement: 'http://${1}',
							permanent: dev ? false : true
						}
					},
					['https-http']: {
						redirectscheme: {
							scheme: 'http',
							permanent: false
						}
					}
				}
			}
		};
		for (const application of data.applications) {
			const { id, port, domain, nakedDomain, isHttps, isWWW, isDualCerts } = application;
			if (isHttps) {
				traefik.http.routers[id] = {
					entrypoints: ['web'],
					rule: `Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`)`,
					middlewares: ['http-to-https'],
					service: id
				};
				traefik.http.routers[`${id}-secure`] = {
					entrypoints: ['websecure'],
					rule: isWWW
						? isDualCerts
							? `Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`)`
							: `Host(\`${nakedDomain}\`)`
						: `Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`)`,
					service: id
				};
			} else {
				traefik.http.routers[id] = {
					entrypoints: ['web'],
					rule: isWWW
						? `Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`)`
						: `Host(\`${nakedDomain}\`)`,
					service: id
				};
				traefik.http.routers[`${id}-secure`] = {
					entrypoints: ['websecure'],
					rule: `Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`)`,
					middlewares: ['https-http'],
					service: id
				};
			}

			traefik.http.services[id] = {
				loadbalancer: {
					servers: [
						{
							url: `http://${id}:${port}`
						}
					]
				}
			};
			if (isHttps && !dev) {
				traefik.http.routers[id].tls = {
					certresolver: 'letsencrypt'
				};
			}
			generateMiddleware({ id, isDualCerts, isWWW, isHttps, traefik });
		}
		for (const service of data.services) {
			const { id, port, domain, nakedDomain, isHttps, isWWW, isDualCerts, scriptName } = service;

			traefik.http.routers[id] = {
				entrypoints: isHttps ? ['web', 'websecure'] : ['web'],
				rule: isWWW
					? isDualCerts
						? `Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`)`
						: `Host(\`${nakedDomain}\`)`
					: `Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`)`,
				service: id
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
			if (isHttps && !dev) {
				traefik.http.routers[id].tls = {
					certresolver: 'letsencrypt'
				};
			}
			if (scriptName) {
				if (!traefik.http.middlewares) traefik.http.middlewares = {};
				traefik.http.middlewares[`${id}-redir`] = {
					replacepathregex: {
						regex: `/js/${scriptName}`,
						replacement: '/js/plausible.js',
						permanent: false
					}
				};
				traefik.http.routers[id].middlewares = [`${id}-redir`];
			}
			generateMiddleware({ id, isDualCerts, isWWW, isHttps, traefik });
		}
		for (const coolify of data.coolify) {
			const { nakedDomain, domain, id, port, isHttps, isWWW, isDualCerts } = coolify;
			traefik.http.routers['coolify'] = {
				entrypoints: isHttps ? ['web', 'websecure'] : ['web'],
				rule: isWWW
					? isDualCerts
						? `Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`)`
						: `Host(\`${nakedDomain}\`)`
					: `Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`)`,
				service: id
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
			if (isHttps && !dev) {
				traefik.http.routers[id].tls = {
					certresolver: 'letsencrypt'
				};
			}
			generateMiddleware({ id, isDualCerts, isWWW, isHttps, traefik });
		}

		return {
			status: 200,
			body: {
				...traefik
			}
		};
	}
};
