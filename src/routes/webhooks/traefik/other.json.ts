import { dev } from '$app/env';
import { getDomain } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (event) => {
	const id = event.url.searchParams.get('id');
	if (id) {
		const privatePort = event.url.searchParams.get('privatePort');
		const publicPort = event.url.searchParams.get('publicPort');
		const type = event.url.searchParams.get('type');
		const address = event.url.searchParams.get('address') || id;
		let traefik = {};
		if (publicPort && type && privatePort) {
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
									servers: [{ address: `${address}:${privatePort}` }]
								}
							}
						}
					}
				};
			} else if (type === 'http') {
				const service = await db.prisma.service.findFirst({
					where: { id },
					include: { minio: true }
				});
				if (service) {
					if (service.type === 'minio') {
						if (service?.minio?.apiFqdn) {
							const {
								minio: { apiFqdn }
							} = service;
							const domain = getDomain(apiFqdn);
							const isHttps = apiFqdn.startsWith('https://');
							traefik = {
								[type]: {
									routers: {
										[id]: {
											entrypoints: [type],
											rule: `Host(\`${domain}\`)`,
											service: id
										}
									},
									services: {
										[id]: {
											loadbalancer: {
												servers: [{ url: `http://${id}:${privatePort}` }]
											}
										}
									}
								}
							};
							if (isHttps) {
								if (dev) {
									traefik[type].routers[id].tls = {
										domains: {
											main: `${domain}`
										}
									};
								} else {
									traefik[type].routers[id].tls = {
										certresolver: 'letsencrypt'
									};
								}
							}
						}
					} else {
						if (service?.fqdn) {
							const domain = getDomain(service.fqdn);
							const isHttps = service.fqdn.startsWith('https://');
							traefik = {
								[type]: {
									routers: {
										[id]: {
											entrypoints: [type],
											rule: `Host(\`${domain}:${privatePort}\`)`,
											service: id
										}
									},
									services: {
										[id]: {
											loadbalancer: {
												servers: [{ url: `http://${id}:${privatePort}` }]
											}
										}
									}
								}
							};
							if (isHttps) {
								if (dev) {
									traefik[type].routers[id].tls = {
										domains: {
											main: `${domain}`
										}
									};
								} else {
									traefik[type].routers[id].tls = {
										certresolver: 'letsencrypt'
									};
								}
							}
						}
					}
				} else {
					return {
						status: 500
					};
				}
			}
		} else {
			return {
				status: 500
			};
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
