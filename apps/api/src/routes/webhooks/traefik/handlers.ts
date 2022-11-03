import { FastifyRequest } from "fastify";
import { errorHandler, getDomain, isDev, prisma, executeDockerCmd, fixType } from "../../../lib/common";
import { TraefikOtherConfiguration } from "./types";
import { OnlyId } from "../../../types";
import { getTemplates } from "../../../lib/services";

async function applicationConfiguration(traefik: any, remoteId: string | null = null) {
	let applications = []
	if (remoteId) {
		applications = await prisma.application.findMany({
			where: { destinationDocker: { id: remoteId } },
			include: { destinationDocker: true, settings: true }
		});
	} else {
		applications = await prisma.application.findMany({
			where: { destinationDocker: { remoteEngine: false } },
			include: { destinationDocker: true, settings: true }
		});
	}
	const configurableApplications = []
	if (applications.length > 0) {
		for (const application of applications) {
			const {
				fqdn,
				id,
				port,
				buildPack,
				dockerComposeConfiguration,
				destinationDocker,
				destinationDockerId,
				settings: { previews, dualCerts, isCustomSSL }
			} = application;
			if (destinationDockerId) {
				const { network, id: dockerId } = destinationDocker;
				const isRunning = true;
				if (buildPack === 'compose') {
					const services = Object.entries(JSON.parse(dockerComposeConfiguration))
					for (const service of services) {
						const [key, value] = service
						if (value.fqdn) {
							const { fqdn } = value
							const domain = getDomain(fqdn);
							const nakedDomain = domain.replace(/^www\./, '');
							const isHttps = fqdn.startsWith('https://');
							const isWWW = fqdn.includes('www.');
							configurableApplications.push({
								id: `${id}-${key}`,
								container: `${id}-${key}`,
								port: value.customPort ? value.customPort : port || 3000,
								domain,
								nakedDomain,
								isRunning,
								isHttps,
								isWWW,
								isDualCerts: dualCerts,
								isCustomSSL,
								pathPrefix: '/'
							});
						}
					}
					continue;
				}

				if (fqdn) {
					const domain = getDomain(fqdn);
					const nakedDomain = domain.replace(/^www\./, '');
					const isHttps = fqdn.startsWith('https://');
					const isWWW = fqdn.includes('www.');
					if (isRunning) {
						configurableApplications.push({
							id,
							container: id,
							port: port || 3000,
							domain,
							nakedDomain,
							isRunning,
							isHttps,
							isWWW,
							isDualCerts: dualCerts,
							isCustomSSL,
							pathPrefix: '/'
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
								configurableApplications.push({
									id: container,
									container,
									port: port || 3000,
									domain: previewDomain,
									isRunning,
									nakedDomain,
									isHttps,
									isWWW,
									isDualCerts: dualCerts,
									isCustomSSL,
									pathPrefix: '/'
								});
							}
						}
					}
				}
			}
		}
		for (const application of configurableApplications) {
			let { id, port, isCustomSSL, pathPrefix, isHttps, nakedDomain, isWWW, domain, dualCerts } = application
			if (isHttps) {
				traefik.http.routers[`${id}-${port || 'default'}`] = generateHttpRouter(`${id}-${port || 'default'}`, nakedDomain, pathPrefix)
				traefik.http.routers[`${id}-${port || 'default'}-secure`] = generateProtocolRedirectRouter(`${id}-${port || 'default'}-secure`, nakedDomain, pathPrefix, 'http-to-https')
				traefik.http.services[`${id}-${port || 'default'}`] = generateLoadBalancerService(id, port)
				if (dualCerts) {
					traefik.http.routers[`${id}-${port || 'default'}-secure`] = {
						entrypoints: ['websecure'],
						rule: `(Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`)) && PathPrefix(\`${pathPrefix}\`)`,
						service: `${id}`,
						tls: isCustomSSL ? true : {
							certresolver: 'letsencrypt'
						},
						middlewares: []
					};
				} else {
					if (isWWW) {
						traefik.http.routers[`${id}-${port || 'default'}-secure-www`] = {
							entrypoints: ['websecure'],
							rule: `Host(\`www.${nakedDomain}\`) && PathPrefix(\`${pathPrefix}\`)`,
							service: `${id}`,
							tls: isCustomSSL ? true : {
								certresolver: 'letsencrypt'
							},
							middlewares: []
						};
						traefik.http.routers[`${id}-${port || 'default'}-secure`] = {
							entrypoints: ['websecure'],
							rule: `Host(\`${nakedDomain}\`) && PathPrefix(\`${pathPrefix}\`)`,
							service: `${id}`,
							tls: {
								domains: {
									main: `${domain}`
								}
							},
							middlewares: ['redirect-to-www']
						};
						traefik.http.routers[`${id}-${port || 'default'}`].middlewares.push('redirect-to-www');
					} else {
						traefik.http.routers[`${id}-${port || 'default'}-secure-www`] = {
							entrypoints: ['websecure'],
							rule: `Host(\`www.${nakedDomain}\`) && PathPrefix(\`${pathPrefix}\`)`,
							service: `${id}`,
							tls: {
								domains: {
									main: `${domain}`
								}
							},
							middlewares: ['redirect-to-non-www']
						};
						traefik.http.routers[`${id}-${port || 'default'}-secure`] = {
							entrypoints: ['websecure'],
							rule: `Host(\`${domain}\`) && PathPrefix(\`${pathPrefix}\`)`,
							service: `${id}`,
							tls: isCustomSSL ? true : {
								certresolver: 'letsencrypt'
							},
							middlewares: []
						};
						traefik.http.routers[`${id}-${port || 'default'}`].middlewares.push('redirect-to-non-www');
					}
				}
			} else {
				traefik.http.routers[`${id}-${port || 'default'}`] = generateHttpRouter(`${id}-${port || 'default'}`, nakedDomain, pathPrefix)
				traefik.http.routers[`${id}-${port || 'default'}-secure`] = generateProtocolRedirectRouter(`${id}-${port || 'default'}`, nakedDomain, pathPrefix, 'https-to-http')
				traefik.http.services[`${id}-${port || 'default'}`] = generateLoadBalancerService(id, port)

				if (!dualCerts) {
					if (isWWW) {
						traefik.http.routers[`${id}-${port || 'default'}`].middlewares.push('redirect-to-www');
						traefik.http.routers[`${id}-${port || 'default'}-secure`].middlewares.push('redirect-to-www');
					} else {
						traefik.http.routers[`${id}-${port || 'default'}`].middlewares.push('redirect-to-non-www');
						traefik.http.routers[`${id}-${port || 'default'}-secure`].middlewares.push('redirect-to-non-www');
					}
				}
			}
		}
	}
}
async function serviceConfiguration(traefik: any, remoteId: string | null = null) {
	let services = [];
	if (remoteId) {
		services = await prisma.service.findMany({
			where: { destinationDocker: { id: remoteId } },
			include: {
				destinationDocker: true,
				persistentStorage: true,
				serviceSecret: true,
				serviceSetting: true,
			},
			orderBy: { createdAt: 'desc' }
		});
	} else {
		services = await prisma.service.findMany({
			where: { destinationDocker: { remoteEngine: false } },
			include: {
				destinationDocker: true,
				persistentStorage: true,
				serviceSecret: true,
				serviceSetting: true,
			},
			orderBy: { createdAt: 'desc' },
		});
	}

	const configurableServices = []
	if (services.length > 0) {
		for (const service of services) {
			let {
				fqdn,
				id,
				type,
				destinationDockerId,
				dualCerts,
				serviceSetting
			} = service;
			if (destinationDockerId) {
				const templates = await getTemplates();
				let found = templates.find((a) => a.type === type);
				if (found) {
					found = JSON.parse(JSON.stringify(found).replaceAll('$$id', id));
					for (const oneService of Object.keys(found.services)) {
						const isProxyConfiguration = found.services[oneService].proxy;
						if (isProxyConfiguration) {
							const { proxy } = found.services[oneService];
							for (let configuration of proxy) {
								const publicPort = service[type]?.publicPort;
								if (configuration.domain) {
									const setting = serviceSetting.find((a) => a.variableName === configuration.domain);
									configuration.domain = configuration.domain.replace(configuration.domain, setting.value);
								}
								const foundPortVariable = serviceSetting.find((a) => a.name.toLowerCase() === 'port')
								if (foundPortVariable) {
									configuration.port = foundPortVariable.value
								}
								if (fqdn) {
									configurableServices.push({
										id: oneService,
										publicPort,
										fqdn,
										dualCerts,
										configuration,
									});
								}
							}
						} else {
							if (found.services[oneService].ports && found.services[oneService].ports.length > 0) {
								let port = found.services[oneService].ports[0]
								const foundPortVariable = serviceSetting.find((a) => a.name.toLowerCase() === 'port')
								if (foundPortVariable) {
									port = foundPortVariable.value
								}
								if (fqdn) {
									configurableServices.push({
										id: oneService,
										configuration: {
											port
										},
										fqdn,
										dualCerts,
									});
								}
							}
						}
					}
				}
			}
		}

		for (const service of configurableServices) {
			let { id, fqdn, dualCerts, configuration, isCustomSSL = false } = service
			let port, pathPrefix, customDomain;
			if (configuration) {
				port = configuration?.port;
				pathPrefix = configuration?.pathPrefix || null;
				customDomain = configuration?.domain;
			}
			if (customDomain) {
				fqdn = customDomain
			}
			const domain = getDomain(fqdn);
			const nakedDomain = domain.replace(/^www\./, '');
			const isHttps = fqdn.startsWith('https://');
			const isWWW = fqdn.includes('www.');
			if (isHttps) {
				traefik.http.routers[`${id}-${port || 'default'}`] = generateHttpRouter(`${id}-${port || 'default'}`, nakedDomain, pathPrefix)
				traefik.http.routers[`${id}-${port || 'default'}-secure`] = generateProtocolRedirectRouter(`${id}-${port || 'default'}-secure`, nakedDomain, pathPrefix, 'http-to-https')
				traefik.http.services[`${id}-${port || 'default'}`] = generateLoadBalancerService(id, port)
				if (dualCerts) {
					traefik.http.routers[`${id}-${port || 'default'}-secure`] = {
						entrypoints: ['websecure'],
						rule: `(Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`)) && PathPrefix(\`${pathPrefix}\`)`,
						service: `${id}`,
						tls: isCustomSSL ? true : {
							certresolver: 'letsencrypt'
						},
						middlewares: []
					};
				} else {
					if (isWWW) {
						traefik.http.routers[`${id}-${port || 'default'}-secure-www`] = {
							entrypoints: ['websecure'],
							rule: `Host(\`www.${nakedDomain}\`) && PathPrefix(\`${pathPrefix}\`)`,
							service: `${id}`,
							tls: isCustomSSL ? true : {
								certresolver: 'letsencrypt'
							},
							middlewares: []
						};
						traefik.http.routers[`${id}-${port || 'default'}-secure`] = {
							entrypoints: ['websecure'],
							rule: `Host(\`${nakedDomain}\`) && PathPrefix(\`${pathPrefix}\`)`,
							service: `${id}`,
							tls: {
								domains: {
									main: `${domain}`
								}
							},
							middlewares: ['redirect-to-www']
						};
						traefik.http.routers[`${id}-${port || 'default'}`].middlewares.push('redirect-to-www');
					} else {
						traefik.http.routers[`${id}-${port || 'default'}-secure-www`] = {
							entrypoints: ['websecure'],
							rule: `Host(\`www.${nakedDomain}\`) && PathPrefix(\`${pathPrefix}\`)`,
							service: `${id}`,
							tls: {
								domains: {
									main: `${domain}`
								}
							},
							middlewares: ['redirect-to-non-www']
						};
						traefik.http.routers[`${id}-${port || 'default'}-secure`] = {
							entrypoints: ['websecure'],
							rule: `Host(\`${domain}\`) && PathPrefix(\`${pathPrefix}\`)`,
							service: `${id}`,
							tls: isCustomSSL ? true : {
								certresolver: 'letsencrypt'
							},
							middlewares: []
						};
						traefik.http.routers[`${id}-${port || 'default'}`].middlewares.push('redirect-to-non-www');
					}
				}
			} else {
				traefik.http.routers[`${id}-${port || 'default'}`] = generateHttpRouter(`${id}-${port || 'default'}`, nakedDomain, pathPrefix)
				traefik.http.routers[`${id}-${port || 'default'}-secure`] = generateProtocolRedirectRouter(`${id}-${port || 'default'}`, nakedDomain, pathPrefix, 'https-to-http')
				traefik.http.services[`${id}-${port || 'default'}`] = generateLoadBalancerService(id, port)

				if (!dualCerts) {
					if (isWWW) {
						traefik.http.routers[`${id}-${port || 'default'}`].middlewares.push('redirect-to-www');
						traefik.http.routers[`${id}-${port || 'default'}-secure`].middlewares.push('redirect-to-www');
					} else {
						traefik.http.routers[`${id}-${port || 'default'}`].middlewares.push('redirect-to-non-www');
						traefik.http.routers[`${id}-${port || 'default'}-secure`].middlewares.push('redirect-to-non-www');
					}
				}
			}
		}
	}
}
async function coolifyConfiguration(traefik: any) {
	const { fqdn, dualCerts } = await prisma.setting.findFirst();
	let coolifyConfigurations = []
	if (fqdn) {
		const domain = getDomain(fqdn);
		const nakedDomain = domain.replace(/^www\./, '');
		const isHttps = fqdn.startsWith('https://');
		const isWWW = fqdn.includes('www.');
		coolifyConfigurations.push({
			id: isDev ? 'host.docker.internal' : 'coolify',
			container: isDev ? 'host.docker.internal' : 'coolify',
			port: 3000,
			domain,
			nakedDomain,
			isHttps,
			isWWW,
			isDualCerts: dualCerts,
			pathPrefix: '/'
		});
	}


	for (const coolify of coolifyConfigurations) {
		const { id, pathPrefix, port, domain, nakedDomain, isHttps, isWWW, isDualCerts, scriptName, type, isCustomSSL } = coolify;
		if (isHttps) {
			traefik.http.routers[`${id}-${port || 'default'}`] = generateHttpRouter(`${id}-${port || 'default'}`, nakedDomain, pathPrefix)
			traefik.http.routers[`${id}-${port || 'default'}-secure`] = generateProtocolRedirectRouter(`${id}-${port || 'default'}-secure`, nakedDomain, pathPrefix, 'http-to-https')
			traefik.http.services[`${id}-${port || 'default'}`] = generateLoadBalancerService(id, port)
			if (dualCerts) {
				traefik.http.routers[`${id}-${port || 'default'}-secure`] = {
					entrypoints: ['websecure'],
					rule: `(Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`)) && PathPrefix(\`${pathPrefix}\`)`,
					service: `${id}`,
					tls: isCustomSSL ? true : {
						certresolver: 'letsencrypt'
					},
					middlewares: []
				};
			} else {
				if (isWWW) {
					traefik.http.routers[`${id}-${port || 'default'}-secure-www`] = {
						entrypoints: ['websecure'],
						rule: `Host(\`www.${nakedDomain}\`) && PathPrefix(\`${pathPrefix}\`)`,
						service: `${id}`,
						tls: isCustomSSL ? true : {
							certresolver: 'letsencrypt'
						},
						middlewares: []
					};
					traefik.http.routers[`${id}-${port || 'default'}-secure`] = {
						entrypoints: ['websecure'],
						rule: `Host(\`${nakedDomain}\`) && PathPrefix(\`${pathPrefix}\`)`,
						service: `${id}`,
						tls: {
							domains: {
								main: `${domain}`
							}
						},
						middlewares: ['redirect-to-www']
					};
					traefik.http.routers[`${id}-${port || 'default'}`].middlewares.push('redirect-to-www');
				} else {
					traefik.http.routers[`${id}-${port || 'default'}-secure-www`] = {
						entrypoints: ['websecure'],
						rule: `Host(\`www.${nakedDomain}\`) && PathPrefix(\`${pathPrefix}\`)`,
						service: `${id}`,
						tls: {
							domains: {
								main: `${domain}`
							}
						},
						middlewares: ['redirect-to-non-www']
					};
					traefik.http.routers[`${id}-${port || 'default'}-secure`] = {
						entrypoints: ['websecure'],
						rule: `Host(\`${domain}\`) && PathPrefix(\`${pathPrefix}\`)`,
						service: `${id}`,
						tls: isCustomSSL ? true : {
							certresolver: 'letsencrypt'
						},
						middlewares: []
					};
					traefik.http.routers[`${id}-${port || 'default'}`].middlewares.push('redirect-to-non-www');
				}
			}
		} else {
			traefik.http.routers[`${id}-${port || 'default'}`] = generateHttpRouter(`${id}-${port || 'default'}`, nakedDomain, pathPrefix)
			traefik.http.routers[`${id}-${port || 'default'}-secure`] = generateProtocolRedirectRouter(`${id}-${port || 'default'}`, nakedDomain, pathPrefix, 'https-to-http')
			traefik.http.services[`${id}-${port || 'default'}`] = generateLoadBalancerService(id, port)

			if (!dualCerts) {
				if (isWWW) {
					traefik.http.routers[`${id}-${port || 'default'}`].middlewares.push('redirect-to-www');
					traefik.http.routers[`${id}-${port || 'default'}-secure`].middlewares.push('redirect-to-www');
				} else {
					traefik.http.routers[`${id}-${port || 'default'}`].middlewares.push('redirect-to-non-www');
					traefik.http.routers[`${id}-${port || 'default'}-secure`].middlewares.push('redirect-to-non-www');
				}
			}
		}
	}
}
function generateLoadBalancerService(id, port) {
	return {
		loadbalancer: {
			servers: [
				{
					url: `http://${id}:${port}`
				}
			]
		}
	};
}
function generateHttpRouter(id, nakedDomain, pathPrefix) {
	return {
		entrypoints: ['web'],
		rule: `(Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`))${pathPrefix ? `&& PathPrefix(\`${pathPrefix}\`)` : ''}`,
		service: `${id}`,
		middlewares: []
	}
}
function generateProtocolRedirectRouter(id, nakedDomain, pathPrefix, fromTo) {
	if (fromTo === 'https-to-http') {
		return {
			entrypoints: ['websecure'],
			rule: `(Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`))${pathPrefix ? `&& PathPrefix(\`${pathPrefix}\`)` : ''}`,
			service: `${id}`,
			tls: {
				domains: {
					main: `${nakedDomain}`
				}
			},
			middlewares: ['redirect-to-http']
		}
	} else if (fromTo === 'http-to-https') {
		return {
			entrypoints: ['web'],
			rule: `(Host(\`${nakedDomain}\`) || Host(\`www.${nakedDomain}\`))${pathPrefix ? `&& PathPrefix(\`${pathPrefix}\`)` : ''}`,
			service: `${id}`,
			middlewares: ['redirect-to-https']
		};
	}

}
export async function traefikConfiguration(request: FastifyRequest<OnlyId>, remote: boolean = false) {
	try {
		const { id = null } = request.params
		const sslpath = '/etc/traefik/acme/custom';

		let certificates = await prisma.certificate.findMany({ where: { team: { applications: { some: { settings: { isCustomSSL: true } } }, destinationDocker: { some: { remoteEngine: false, isCoolifyProxyUsed: true } } } } })

		if (remote) {
			certificates = await prisma.certificate.findMany({ where: { team: { applications: { some: { settings: { isCustomSSL: true } } }, destinationDocker: { some: { id, remoteEngine: true, isCoolifyProxyUsed: true, remoteVerified: true } } } } })
		}

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
		await applicationConfiguration(traefik, id)
		await serviceConfiguration(traefik, id)
		if (!remote) {
			await coolifyConfiguration(traefik)
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
						include: { serviceSetting: true }
					});
					if (service) {
						if (service.type === 'minio') {
							const domainSetting = service.serviceSetting.find((a) => a.name === 'MINIO_SERVER_URL')?.value
							const domain = getDomain(domainSetting);
							const isHttps = domainSetting.startsWith('https://');
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
