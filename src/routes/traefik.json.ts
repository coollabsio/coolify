import { dev } from '$app/env';
import { asyncExecShell, getDomain, getEngine } from '$lib/common';
import { supportedServiceTypesAndVersions } from '$lib/components/common';
import * as db from '$lib/database';
import { listServicesWithIncludes } from '$lib/database';
import { checkContainer } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (event) => {
	const id = event.url.searchParams.get('id');
	if (id) {
		const privatePort = event.url.searchParams.get('privatePort');
		const publicPort = event.url.searchParams.get('publicPort');
		const type = event.url.searchParams.get('type');
		let traefik = {};
		if (publicPort) {
			traefik = {
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
								servers: []
							}
						}
					}
				}
			};
		}
		if (type === 'tcp') {
			traefik[type].services[id].loadbalancer.servers.push({ address: `${id}:${privatePort}` });
		} else {
			traefik[type].services[id].loadbalancer.servers.push({ url: `http://${id}:${privatePort}` });
		}
		return {
			status: 200,
			body: {
				...traefik
			}
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
				settings: { previews },
				updatedAt
			} = application;
			if (destinationDockerId) {
				const { engine, network } = destinationDocker;
				const isRunning = await checkContainer(engine, id);
				if (fqdn) {
					const domain = getDomain(fqdn);
					const isHttps = fqdn.startsWith('https://');
					const isWWW = fqdn.includes('www.');
					const redirectValue = `${isHttps ? 'https://' : 'http://'}${domain}%[capture.req.uri]`;
					if (isRunning) {
						data.applications.push({
							id,
							port: port || 3000,
							domain,
							isRunning,
							isHttps,
							redirectValue,
							redirectTo: isWWW ? domain.replace('www.', '') : 'www.' + domain,
							updatedAt: updatedAt.getTime()
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
									redirectValue,
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
						const isHttps = fqdn.startsWith('https://');
						const isWWW = fqdn.includes('www.');
						const redirectValue = `${isHttps ? 'https://' : 'http://'}${domain}%[capture.req.uri]`;
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
								isRunning,
								isHttps,
								redirectValue,
								redirectTo: isWWW ? domain.replace('www.', '') : 'www.' + domain,
								updatedAt: updatedAt.getTime(),
								scriptName
							});
						}
					}
				}
			}
		}

		const { fqdn } = await db.prisma.setting.findFirst();
		if (fqdn) {
			const domain = getDomain(fqdn);
			const isHttps = fqdn.startsWith('https://');
			const isWWW = fqdn.includes('www.');
			const redirectValue = `${isHttps ? 'https://' : 'http://'}${domain}%[capture.req.uri]`;
			data.coolify.push({
				id: dev ? 'host.docker.internal' : 'coolify',
				port: 3000,
				domain,
				isHttps,
				redirectValue,
				redirectTo: isWWW ? domain.replace('www.', '') : 'www.' + domain
			});
		}
		const traefik = {
			http: {
				routers: {},
				services: {}
			}
		};
		for (const application of data.applications) {
			const { id, port, domain, isHttps, redirectValue, redirectTo, updatedAt } = application;
			traefik.http.routers[id] = {
				entrypoints: ['web'],
				rule: `Host(\`${domain}\`)`,
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
		}
		for (const application of data.services) {
			const { id, port, domain, isHttps, redirectValue, redirectTo, updatedAt, scriptName } =
				application;

			traefik.http.routers[id] = {
				entrypoints: ['web'],
				rule: `Host(\`${domain}\`)`,
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
			if (scriptName) {
				if (!traefik.http.middlewares) traefik.http.middlewares = {};
				traefik.http.middlewares[`${id}-redir`] = {
					replacepathregex: {
						regex: `/js/${scriptName}`,
						replacement: '/js/plausible.js'
					}
				};
				traefik.http.routers[id].middlewares = [`${id}-redir`];
			}
		}
		for (const application of data.coolify) {
			const { domain, id, port } = application;
			traefik.http.routers['coolify'] = {
				entrypoints: ['web'],
				rule: `Host(\`${domain}\`)`,
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
		}

		return {
			status: 200,
			body: {
				...traefik
				// "http": {
				// 	"routers": {
				// 		"coolify": {
				// 			"entrypoints": [
				// 				"web"
				// 			],
				// 			"middlewares": [
				// 				"coolify-hc"
				// 			],
				// 			"rule": "Host(`staging.coolify.io`)",
				// 			"service": "coolify"
				// 		},
				// 		"static.example.coolify.io": {
				// 			"entrypoints": [
				// 				"web"
				// 			],
				// 			"rule": "Host(`static.example.coolify.io`)",
				// 			"service": "static.example.coolify.io"
				// 		}
				// 	},
				// 	"services": {
				// 		"coolify": {
				// 			"loadbalancer": {
				// 				"servers": [
				// 					{
				// 						"url": "http://coolify:3000"
				// 					}
				// 				]
				// 			}
				// 		},
				// 		"static.example.coolify.io": {
				// 			"loadbalancer": {
				// 				"servers": [
				// 					{
				// 						"url": "http://cl32p06f58068518cs3thg6vbc7:80"
				// 					}
				// 				]
				// 			}
				// 		}
				// 	},
				// 	"middlewares": {
				// 		"coolify-hc": {
				// 			"replacepathregex": {
				// 				"regex": "/dead.json",
				// 				"replacement": "/undead.json"
				// 			}
				// 		}
				// 	}
				// }
			}
		};
	}
};
