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
			if (type === 'tcp') {
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
									servers: [{ address: `${id}:${privatePort}` }]
								}
							}
						}
					}
				};
			} else if (type === 'http') {
				const service = await db.prisma.service.findFirst({ where: { id } });
				if (service?.fqdn) {
					const domain = getDomain(service.fqdn);
					traefik = {
						[type]: {
							routers: {
								[`${id}-${publicPort}`]: {
									entrypoints: [type],
									rule: `Host(\`${domain}\`)`,
									service: `${id}-${publicPort}`
								}
							},
							services: {
								[`${id}-${publicPort}`]: {
									loadbalancer: {
										servers: [{ url: `http://${id}:${privatePort}` }]
									}
								}
							}
						}
					};
				}
			}
		}
		return {
			status: 200,
			body: {
				...traefik
			}
		};
	}
	return {
		status: 500
	};
};
