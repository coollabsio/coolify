import { asyncSleep, decrypt, fixType, generateRangeArray, getDomain, getTemplates } from '../../../lib/common';
import bcrypt from 'bcryptjs';
import { prisma } from '../../../prisma';
import crypto from 'crypto';
import { executeCommand } from '../../../lib/executeCommand';

export async function parseAndFindServiceTemplates(
	service: any,
	workdir?: string,
	isDeploy: boolean = false
) {
	const templates = await getTemplates();
	const foundTemplate = templates.find((t) => fixType(t.type) === service.type);
	let parsedTemplate = {};
	if (foundTemplate) {
		if (!isDeploy) {
			for (const [key, value] of Object.entries(foundTemplate.services)) {
				const realKey = key.replace('$$id', service.id);
				let name = value.name;
				if (!name) {
					if (Object.keys(foundTemplate.services).length === 1) {
						name = foundTemplate.name || service.name.toLowerCase();
					} else {
						if (key === '$$id') {
							name =
								foundTemplate.name || key.replaceAll('$$id-', '') || service.name.toLowerCase();
						} else {
							name = key.replaceAll('$$id-', '') || service.name.toLowerCase();
						}
					}
				}
				parsedTemplate[realKey] = {
					value,
					name,
					documentation:
						value.documentation || foundTemplate.documentation || 'https://docs.coollabs.io',
					image: value.image,
					files: value?.files,
					environment: [],
					fqdns: [],
					hostPorts: [],
					proxy: {}
				};
				if (value.environment?.length > 0) {
					for (const env of value.environment) {
						let [envKey, ...envValue] = env.split('=');
						envValue = envValue.join('=');
						let variable = null;
						if (foundTemplate?.variables) {
							variable =
								foundTemplate?.variables.find((v) => v.name === envKey) ||
								foundTemplate?.variables.find((v) => v.id === envValue);
						}
						if (variable) {
							const id = variable.id.replaceAll('$$', '');
							const label = variable?.label;
							const description = variable?.description;
							const defaultValue = variable?.defaultValue;
							const main = variable?.main || '$$id';
							const type = variable?.type || 'input';
							const placeholder = variable?.placeholder || '';
							const readOnly = variable?.readOnly || false;
							const required = variable?.required || false;
							if (envValue.startsWith('$$config') || variable?.showOnConfiguration) {
								if (envValue.startsWith('$$config_coolify')) {
									continue;
								}
								parsedTemplate[realKey].environment.push({
									id,
									name: envKey,
									value: envValue,
									main,
									label,
									description,
									defaultValue,
									type,
									placeholder,
									required,
									readOnly
								});
							}
						}
					}
				}
				if (value?.proxy && value.proxy.length > 0) {
					for (const proxyValue of value.proxy) {
						if (proxyValue.domain) {
							const variable = foundTemplate?.variables.find((v) => v.id === proxyValue.domain);
							if (variable) {
								const { id, name, label, description, defaultValue, required = false } = variable;
								const found = await prisma.serviceSetting.findFirst({
									where: { serviceId: service.id, variableName: proxyValue.domain }
								});
								parsedTemplate[realKey].fqdns.push({
									id,
									name,
									value: found?.value || '',
									label,
									description,
									defaultValue,
									required
								});
							}
						}
						if (proxyValue.hostPort) {
							const variable = foundTemplate?.variables.find((v) => v.id === proxyValue.hostPort);
							if (variable) {
								const { id, name, label, description, defaultValue, required = false } = variable;
								const found = await prisma.serviceSetting.findFirst({
									where: { serviceId: service.id, variableName: proxyValue.hostPort }
								});
								parsedTemplate[realKey].hostPorts.push({
									id,
									name,
									value: found?.value || '',
									label,
									description,
									defaultValue,
									required
								});
							}
						}
					}
				}
			}
		} else {
			parsedTemplate = foundTemplate;
		}
		let strParsedTemplate = JSON.stringify(parsedTemplate);

		// replace $$id and $$workdir
		strParsedTemplate = strParsedTemplate.replaceAll('$$id', service.id);
		strParsedTemplate = strParsedTemplate.replaceAll(
			'$$core_version',
			service.version || foundTemplate.defaultVersion
		);

		// replace $$workdir
		if (workdir) {
			strParsedTemplate = strParsedTemplate.replaceAll('$$workdir', workdir);
		}

		// replace $$config
		if (service.serviceSetting.length > 0) {
			for (const setting of service.serviceSetting) {
				const { value, variableName } = setting;
				const regex = new RegExp(`\\$\\$config_${variableName.replace('$$config_', '')}\"`, 'gi');
				if (value === '$$generate_fqdn') {
					strParsedTemplate = strParsedTemplate.replaceAll(regex, service.fqdn + '"' || '' + '"');
				} else if (value === '$$generate_fqdn_slash') {
					strParsedTemplate = strParsedTemplate.replaceAll(regex, service.fqdn + '/' + '"');
				} else if (value === '$$generate_domain') {
					strParsedTemplate = strParsedTemplate.replaceAll(regex, getDomain(service.fqdn) + '"');
				} else if (service.destinationDocker?.network && value === '$$generate_network') {
					strParsedTemplate = strParsedTemplate.replaceAll(
						regex,
						service.destinationDocker.network + '"'
					);
				} else {
					strParsedTemplate = strParsedTemplate.replaceAll(regex, value + '"');
				}
			}
		}

		// replace $$secret
		if (service.serviceSecret.length > 0) {
			for (const secret of service.serviceSecret) {
				let { name, value } = secret;
				name = name.toLowerCase();
				const regexHashed = new RegExp(`\\$\\$hashed\\$\\$secret_${name}`, 'gi');
				const regex = new RegExp(`\\$\\$secret_${name}`, 'gi');
				if (value) {
					strParsedTemplate = strParsedTemplate.replaceAll(
						regexHashed,
						bcrypt.hashSync(value.replaceAll('"', '\\"'), 10)
					);
					strParsedTemplate = strParsedTemplate.replaceAll(regex, value.replaceAll('"', '\\"'));
				} else {
					strParsedTemplate = strParsedTemplate.replaceAll(regexHashed, '');
					strParsedTemplate = strParsedTemplate.replaceAll(regex, '');
				}
			}
		}
		parsedTemplate = JSON.parse(strParsedTemplate);
	}
	return parsedTemplate;
}
export function generatePassword({
	length = 24,
	symbols = false,
	isHex = false
}: { length?: number; symbols?: boolean; isHex?: boolean } | null): string {
	if (isHex) {
		return crypto.randomBytes(length).toString('hex');
	}
	const password = generator.generate({
		length,
		numbers: true,
		strict: true,
		symbols
	});

	return password;
}

export async function getFreePublicPort({ id, remoteEngine, engine, remoteIpAddress }) {
	const { default: isReachable } = await import('is-port-reachable');
	const data = await prisma.setting.findFirst();
	const { minPort, maxPort } = data;
	if (remoteEngine) {
		const dbUsed = await (
			await prisma.database.findMany({
				where: {
					publicPort: { not: null },
					id: { not: id },
					destinationDocker: { remoteIpAddress }
				},
				select: { publicPort: true }
			})
		).map((a) => a.publicPort);
		const wpFtpUsed = await (
			await prisma.wordpress.findMany({
				where: {
					ftpPublicPort: { not: null },
					id: { not: id },
					service: { destinationDocker: { remoteIpAddress } }
				},
				select: { ftpPublicPort: true }
			})
		).map((a) => a.ftpPublicPort);
		const wpUsed = await (
			await prisma.wordpress.findMany({
				where: {
					mysqlPublicPort: { not: null },
					id: { not: id },
					service: { destinationDocker: { remoteIpAddress } }
				},
				select: { mysqlPublicPort: true }
			})
		).map((a) => a.mysqlPublicPort);
		const minioUsed = await (
			await prisma.minio.findMany({
				where: {
					publicPort: { not: null },
					id: { not: id },
					service: { destinationDocker: { remoteIpAddress } }
				},
				select: { publicPort: true }
			})
		).map((a) => a.publicPort);
		const usedPorts = [...dbUsed, ...wpFtpUsed, ...wpUsed, ...minioUsed];
		const range = generateRangeArray(minPort, maxPort);
		const availablePorts = range.filter((port) => !usedPorts.includes(port));
		for (const port of availablePorts) {
			const found = await isReachable(port, { host: remoteIpAddress });
			if (!found) {
				return port;
			}
		}
		return false;
	} else {
		const dbUsed = await (
			await prisma.database.findMany({
				where: { publicPort: { not: null }, id: { not: id }, destinationDocker: { engine } },
				select: { publicPort: true }
			})
		).map((a) => a.publicPort);
		const wpFtpUsed = await (
			await prisma.wordpress.findMany({
				where: {
					ftpPublicPort: { not: null },
					id: { not: id },
					service: { destinationDocker: { engine } }
				},
				select: { ftpPublicPort: true }
			})
		).map((a) => a.ftpPublicPort);
		const wpUsed = await (
			await prisma.wordpress.findMany({
				where: {
					mysqlPublicPort: { not: null },
					id: { not: id },
					service: { destinationDocker: { engine } }
				},
				select: { mysqlPublicPort: true }
			})
		).map((a) => a.mysqlPublicPort);
		const minioUsed = await (
			await prisma.minio.findMany({
				where: {
					publicPort: { not: null },
					id: { not: id },
					service: { destinationDocker: { engine } }
				},
				select: { publicPort: true }
			})
		).map((a) => a.publicPort);
		const usedPorts = [...dbUsed, ...wpFtpUsed, ...wpUsed, ...minioUsed];
		const range = generateRangeArray(minPort, maxPort);
		const availablePorts = range.filter((port) => !usedPorts.includes(port));
		for (const port of availablePorts) {
			const found = await isReachable(port, { host: 'localhost' });
			if (!found) {
				return port;
			}
		}
		return false;
	}
}

export async function verifyAndDecryptServiceSecrets(id: string) {
	const secrets = await prisma.serviceSecret.findMany({ where: { serviceId: id } })
	let decryptedSecrets = secrets.map(secret => {
		const { name, value } = secret
		if (value) {
			let rawValue = decrypt(value)
			rawValue = rawValue.replaceAll(/\$/gi, '$$$')
			return { name, value: rawValue }
		}
		return { name, value }

	})
	return decryptedSecrets
}

export function persistentVolumes(id, persistentStorage, config) {
	let volumeSet = new Set();
	if (Object.keys(config).length > 0) {
		for (const [key, value] of Object.entries(config)) {
			if (value.volumes) {
				for (const volume of value.volumes) {
					if (!volume.startsWith('/')) {
						volumeSet.add(volume);
					}
				}
			}
		}
	}
	const volumesArray = Array.from(volumeSet);
	const persistentVolume =
		persistentStorage?.map((storage) => {
			return `${id}${storage.path.replace(/\//gi, '-')}:${storage.path}`;
		}) || [];

	let volumes = [...persistentVolume];
	if (volumesArray) volumes = [...volumesArray, ...volumes];
	const composeVolumes =
		(volumes.length > 0 &&
			volumes.map((volume) => {
				return {
					[`${volume.split(':')[0]}`]: {
						name: volume.split(':')[0]
					}
				};
			})) ||
		[];

	const volumeMounts = Object.assign({}, ...composeVolumes) || {};
	return { volumeMounts };
}

export async function startServiceContainers(fastify, id, teamId, dockerId, composeFileDestination) {
    try {
        // fastify.io.to(teamId).emit(`start-service`, { serviceId: id, state: 'Pulling images...' })
        await executeCommand({ dockerId, command: `docker compose -f ${composeFileDestination} pull` })
    } catch (error) { }
    // fastify.io.to(teamId).emit(`start-service`, { serviceId: id, state: 'Building images...' })
    await executeCommand({ dockerId, command: `docker compose -f ${composeFileDestination} build --no-cache` })
    // fastify.io.to(teamId).emit(`start-service`, { serviceId: id, state: 'Creating containers...' })
    await executeCommand({ dockerId, command: `docker compose -f ${composeFileDestination} create` })
    // fastify.io.to(teamId).emit(`start-service`, { serviceId: id, state: 'Starting containers...' })
    await executeCommand({ dockerId, command: `docker compose -f ${composeFileDestination} start` })
    await asyncSleep(1000);
    await executeCommand({ dockerId, command: `docker compose -f ${composeFileDestination} up -d` })
    // fastify.io.to(teamId).emit(`start-service`, { serviceId: id, state: 0 })
}