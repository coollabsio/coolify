import { FastifyRequest } from "fastify";
import { errorHandler, getDomain, isDev, prisma, executeDockerCmd } from "../../../lib/common";
import { supportedServiceTypesAndVersions } from "../../../lib/services/supportedVersions";
import { includeServices } from "../../../lib/services/common";
import { TraefikOtherConfiguration } from "./types";
import { OnlyId } from "../../../types";

function configureMiddleware(
	{ id, container, port, domain, nakedDomain, isHttps, isWWW, isDualCerts, scriptName, type, isCustomSSL },
	traefik
) {
	if (isHttps) {
		traefik.http.routers[id] = {
			entrypoints: ['web'],
			rule: `(Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`)) && PathPrefix(\`/\`)`,
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
		if (type === 'appwrite') {
			traefik.http.routers[`${id}-realtime`] = {
				entrypoints: ['websecure'],
				rule: `(Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`)) && PathPrefix(\`/v1/realtime\`)`,
				service: `${`${id}-realtime`}`,
				tls: {
					domains: {
						main: `${domain}`
					}
				},
				middlewares: []
			};


			traefik.http.services[`${id}-realtime`] = {
				loadbalancer: {
					servers: [
						{
							url: `http://${container}-realtime:${port}`
						}
					]
				}
			};
		}
		if (isDualCerts) {
			traefik.http.routers[`${id}-secure`] = {
				entrypoints: ['websecure'],
				rule: `(Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`)) && PathPrefix(\`/\`)`,
				service: `${id}`,
				tls: isCustomSSL ? true : {
					certresolver: 'letsencrypt'
				},
				middlewares: []
			};
		} else {
			if (isWWW) {
				traefik.http.routers[`${id}-secure-www`] = {
					entrypoints: ['websecure'],
					rule: `Host(\`www.${nakedDomain}\`) && PathPrefix(\`/\`)`,
					service: `${id}`,
					tls: isCustomSSL ? true : {
						certresolver: 'letsencrypt'
					},
					middlewares: []
				};
				traefik.http.routers[`${id}-secure`] = {
					entrypoints: ['websecure'],
					rule: `Host(\`${nakedDomain}\`) && PathPrefix(\`/\`)`,
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
					rule: `Host(\`www.${nakedDomain}\`) && PathPrefix(\`/\`)`,
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
					rule: `Host(\`${domain}\`) && PathPrefix(\`/\`)`,
					service: `${id}`,
					tls: isCustomSSL ? true : {
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
			rule: `(Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`)) && PathPrefix(\`/\`)`,
			service: `${id}`,
			middlewares: []
		};

		traefik.http.routers[`${id}-secure`] = {
			entrypoints: ['websecure'],
			rule: `(Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`)) && PathPrefix(\`/\`)`,
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
		if (type === 'appwrite') {
			traefik.http.routers[`${id}-realtime`] = {
				entrypoints: ['web'],
				rule: `(Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`)) && PathPrefix(\`/v1/realtime\`)`,
				service: `${id}-realtime`,
				middlewares: []
			};
			traefik.http.services[`${id}-realtime`] = {
				loadbalancer: {
					servers: [
						{
							url: `http://${container}-realtime:${port}`
						}
					]
				}
			};
		}

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

	if (type === 'plausibleanalytics' && scriptName && scriptName !== 'plausible.js') {
		if (!traefik.http.routers[`${id}`].middlewares.includes(`${id}-redir`)) {
			traefik.http.routers[`${id}`].middlewares.push(`${id}-redir`);
		}
		if (!traefik.http.routers[`${id}-secure`].middlewares.includes(`${id}-redir`)) {
			traefik.http.routers[`${id}-secure`].middlewares.push(`${id}-redir`);
		}
	}
}


export async function traefikConfiguration(request, reply) {
	try {
		const sslpath = '/etc/traefik/acme/custom';
		const certificates = await prisma.certificate.findMany({ where: { team: { applications: { some: { settings: { isCustomSSL: true } } }, destinationDocker: { some: { remoteEngine: false, isCoolifyProxyUsed: true } } } } })
		let parsedCertificates = []
		for (const certificate of certificates) {
			parsedCertificates.push({
				certFile: `${sslpath}/${certificate.id}-cert.pem`,
				keyFile: `${sslpath}/${certificate.id}-key.pem`
			})
		}
		const traefik = {
			tls: {
				certificates: parsedCertificates
			},
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
		const applications = await prisma.application.findMany({
			where: { destinationDocker: { remoteEngine: false } },
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
				settings: { previews, dualCerts, isCustomSSL }
			} = application;
			if (destinationDockerId) {
				const { network, id: dockerId } = destinationDocker;
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
							isDualCerts: dualCerts,
							isCustomSSL
						});
					}
					if (previews) {
						const { stdout } = await executeDockerCmd({ dockerId, command: `docker container ls --filter="status=running" --filter="network=${network}" --filter="name=${id}-" --format="{{json .Names}}"` })
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
									isDualCerts: dualCerts,
									isCustomSSL
								});
							}
						}
					}
				}
			}
		}
		const services: any = await prisma.service.findMany({
			where: { destinationDocker: { remoteEngine: false } },
			include: includeServices,
			orderBy: { createdAt: 'desc' },
		});

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

		const { fqdn, dualCerts } = await prisma.setting.findFirst();
		if (fqdn) {
			const domain = getDomain(fqdn);
			const nakedDomain = domain.replace(/^www\./, '');
			const isHttps = fqdn.startsWith('https://');
			const isWWW = fqdn.includes('www.');
			data.coolify.push({
				id: isDev ? 'host.docker.internal' : 'coolify',
				container: isDev ? 'host.docker.internal' : 'coolify',
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
			...traefik
		}
	} catch ({ status, message }) {
		return errorHandler({ status, message })
	}
}

export async function traefikOtherConfiguration(request: FastifyRequest<TraefikOtherConfiguration>) {
	try {
		const { id } = request.query
		if (id) {
			const { privatePort, publicPort, type, address = id } = request.query
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
					const service = await prisma.service.findFirst({
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
									if (isDev) {
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
									if (isDev) {
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
						throw { status: 500 }
					}
				}
			} else {
				throw { status: 500 }
			}
			return {
				...traefik
			};
		}
		throw { status: 500 }
	} catch ({ status, message }) {
		return errorHandler({ status, message })
	}
}

export async function remoteTraefikConfiguration(request: FastifyRequest<OnlyId>) {
	const { id } = request.params
	try {
		const sslpath = '/etc/traefik/acme/custom';
		const certificates = await prisma.certificate.findMany({ where: { team: { applications: { some: { settings: { isCustomSSL: true } } }, destinationDocker: { some: { id, remoteEngine: true, isCoolifyProxyUsed: true, remoteVerified: true } } } } })
		let parsedCertificates = []
		for (const certificate of certificates) {
			parsedCertificates.push({
				certFile: `${sslpath}/${certificate.id}-cert.pem`,
				keyFile: `${sslpath}/${certificate.id}-key.pem`
			})
		}
		const traefik = {
			tls: {
				certificates: parsedCertificates
			},
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
		const applications = await prisma.application.findMany({
			where: { destinationDocker: { id } },
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
				const { id: dockerId, network } = destinationDocker;
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
						const { stdout } = await executeDockerCmd({ dockerId, command: `docker container ls --filter="status=running" --filter="network=${network}" --filter="name=${id}-" --format="{{json .Names}}"` })
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
		const services: any = await prisma.service.findMany({
			where: { destinationDocker: { id } },
			include: includeServices,
			orderBy: { createdAt: 'desc' }
		});

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
			...traefik
		}
	} catch ({ status, message }) {
		return errorHandler({ status, message })
	}
}