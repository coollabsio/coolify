import { asyncExecShell, getDomain, getEngine } from '$lib/common';
import * as db from '$lib/database';
import { checkContainer } from '$lib/haproxy';

export const get = async () => {
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
};
