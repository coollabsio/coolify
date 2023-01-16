import { z } from 'zod';
import yaml from 'js-yaml';
import fs from 'fs/promises';
import path from 'path';
import { privateProcedure, router } from '../../trpc';
import {
	createDirectories,
	decrypt,
	encrypt,
	fixType,
	getTags,
	getTemplates,
	isARM,
	isDev,
	listSettings,
	makeLabelForServices,
	removeService
} from '../../../lib/common';
import { prisma } from '../../../prisma';
import { executeCommand } from '../../../lib/executeCommand';
import {
	generatePassword,
	getFreePublicPort,
	parseAndFindServiceTemplates,
	persistentVolumes,
	startServiceContainers,
	verifyAndDecryptServiceSecrets
} from './lib';
import { checkContainer, defaultComposeConfiguration, stopTcpHttpProxy } from '../../../lib/docker';
import cuid from 'cuid';
import { day } from '../../../lib/dayjs';

export const servicesRouter = router({
	getLogs: privateProcedure
		.input(
			z.object({
				id: z.string(),
				containerId: z.string(),
				since: z.number().optional().default(0)
			})
		)
		.query(async ({ input, ctx }) => {
			let { id, containerId, since } = input;
			if (since !== 0) {
				since = day(since).unix();
			}
			const {
				destinationDockerId,
				destinationDocker: { id: dockerId }
			} = await prisma.service.findUnique({
				where: { id },
				include: { destinationDocker: true }
			});
			if (destinationDockerId) {
				try {
					const { default: ansi } = await import('strip-ansi');
					const { stdout, stderr } = await executeCommand({
						dockerId,
						command: `docker logs --since ${since} --tail 5000 --timestamps ${containerId}`
					});
					const stripLogsStdout = stdout
						.toString()
						.split('\n')
						.map((l) => ansi(l))
						.filter((a) => a);
					const stripLogsStderr = stderr
						.toString()
						.split('\n')
						.map((l) => ansi(l))
						.filter((a) => a);
					return {
						data: {
							logs: stripLogsStderr.concat(stripLogsStdout)
						}
					};
				} catch (error) {
					const { statusCode, stderr } = error;
					if (stderr.startsWith('Error: No such container')) {
						return {
							data: {
								logs: [],
								noContainer: true
							}
						};
					}
					if (statusCode === 404) {
						return {
							data: {
								logs: []
							}
						};
					}
				}
			}
			return {
				message: 'No logs found.'
			};
		}),
	deleteStorage: privateProcedure
		.input(
			z.object({
				storageId: z.string()
			})
		)
		.mutation(async ({ input, ctx }) => {
			const { storageId } = input;
			await prisma.servicePersistentStorage.deleteMany({ where: { id: storageId } });
		}),
	saveStorage: privateProcedure
		.input(
			z.object({
				id: z.string(),
				path: z.string(),
				isNewStorage: z.boolean(),
				storageId: z.string().optional().nullable(),
				containerId: z.string().optional()
			})
		)
		.mutation(async ({ input, ctx }) => {
			const { id, path, isNewStorage, storageId, containerId } = input;

			if (isNewStorage) {
				const volumeName = `${id}-custom${path.replace(/\//gi, '-')}`;
				const found = await prisma.servicePersistentStorage.findFirst({
					where: { path, containerId }
				});
				if (found) {
					throw {
						status: 500,
						message: 'Persistent storage already exists for this container and path.'
					};
				}
				await prisma.servicePersistentStorage.create({
					data: { path, volumeName, containerId, service: { connect: { id } } }
				});
			} else {
				await prisma.servicePersistentStorage.update({
					where: { id: storageId },
					data: { path, containerId }
				});
			}
		}),
	getStorages: privateProcedure
		.input(z.object({ id: z.string() }))
		.query(async ({ input, ctx }) => {
			const { id } = input;
			const persistentStorages = await prisma.servicePersistentStorage.findMany({
				where: { serviceId: id }
			});
			return {
				success: true,
				data: {
					persistentStorages
				}
			};
		}),
	deleteSecret: privateProcedure
		.input(z.object({ id: z.string(), name: z.string() }))
		.mutation(async ({ input, ctx }) => {
			const { id, name } = input;
			await prisma.serviceSecret.deleteMany({ where: { serviceId: id, name } });
		}),
	saveService: privateProcedure
		.input(
			z.object({
				id: z.string(),
				name: z.string(),
				fqdn: z.string().optional(),
				exposePort: z.string().optional(),
				type: z.string(),
				serviceSetting: z.any(),
				version: z.string().optional()
			})
		)
		.mutation(async ({ input, ctx }) => {
			const teamId = ctx.user?.teamId;
			let { id, name, fqdn, exposePort, type, serviceSetting, version } = input;
			if (fqdn) fqdn = fqdn.toLowerCase();
			if (exposePort) exposePort = Number(exposePort);
			type = fixType(type);

			const data = {
				fqdn,
				name,
				exposePort,
				version
			};
			const templates = await getTemplates();
			const service = await prisma.service.findUnique({ where: { id } });
			const foundTemplate = templates.find((t) => fixType(t.type) === fixType(service.type));
			for (const setting of serviceSetting) {
				let { id: settingId, name, value, changed = false, isNew = false, variableName } = setting;
				if (value) {
					if (changed) {
						await prisma.serviceSetting.update({ where: { id: settingId }, data: { value } });
					}
					if (isNew) {
						if (!variableName) {
							variableName = foundTemplate?.variables.find((v) => v.name === name).id;
						}
						await prisma.serviceSetting.create({
							data: { name, value, variableName, service: { connect: { id } } }
						});
					}
				}
			}
			await prisma.service.update({
				where: { id },
				data
			});
		}),
	createSecret: privateProcedure
		.input(
			z.object({
				id: z.string(),
				name: z.string(),
				value: z.string(),
				isBuildSecret: z.boolean().optional(),
				isPRMRSecret: z.boolean().optional(),
				isNew: z.boolean().optional()
			})
		)
		.mutation(async ({ input }) => {
			let { id, name, value, isNew } = input;
			if (isNew) {
				const found = await prisma.serviceSecret.findFirst({ where: { name, serviceId: id } });
				if (found) {
					throw `Secret ${name} already exists.`;
				} else {
					value = encrypt(value.trim());
					await prisma.serviceSecret.create({
						data: { name, value, service: { connect: { id } } }
					});
				}
			} else {
				value = encrypt(value.trim());
				const found = await prisma.serviceSecret.findFirst({ where: { serviceId: id, name } });

				if (found) {
					await prisma.serviceSecret.updateMany({
						where: { serviceId: id, name },
						data: { value }
					});
				} else {
					await prisma.serviceSecret.create({
						data: { name, value, service: { connect: { id } } }
					});
				}
			}
		}),
	getSecrets: privateProcedure.input(z.object({ id: z.string() })).query(async ({ input, ctx }) => {
		const { id } = input;
		const teamId = ctx.user?.teamId;
		const service = await getServiceFromDB({ id, teamId });
		let secrets = await prisma.serviceSecret.findMany({
			where: { serviceId: id },
			orderBy: { createdAt: 'desc' }
		});
		const templates = await getTemplates();
		if (!templates) throw new Error('No templates found. Please contact support.');
		const foundTemplate = templates.find((t) => fixType(t.type) === service.type);
		secrets = secrets.map((secret) => {
			const foundVariable = foundTemplate?.variables?.find((v) => v.name === secret.name) || null;
			if (foundVariable) {
				secret.readOnly = foundVariable.readOnly;
			}
			secret.value = decrypt(secret.value);
			return secret;
		});

		return {
			success: true,
			data: {
				secrets
			}
		};
	}),
	wordpress: privateProcedure
		.input(z.object({ id: z.string(), ftpEnabled: z.boolean() }))
		.mutation(async ({ input, ctx }) => {
			const { id } = input;
			const teamId = ctx.user?.teamId;
			const {
				service: {
					destinationDocker: { engine, remoteEngine, remoteIpAddress }
				}
			} = await prisma.wordpress.findUnique({
				where: { serviceId: id },
				include: { service: { include: { destinationDocker: true } } }
			});

			const publicPort = await getFreePublicPort({ id, remoteEngine, engine, remoteIpAddress });

			let ftpUser = cuid();
			let ftpPassword = generatePassword({});

			const hostkeyDir = isDev ? '/tmp/hostkeys' : '/app/ssl/hostkeys';
			try {
				const data = await prisma.wordpress.update({
					where: { serviceId: id },
					data: { ftpEnabled },
					include: { service: { include: { destinationDocker: true } } }
				});
				const {
					service: { destinationDockerId, destinationDocker },
					ftpPublicPort,
					ftpUser: user,
					ftpPassword: savedPassword,
					ftpHostKey,
					ftpHostKeyPrivate
				} = data;
				const { network, engine } = destinationDocker;
				if (ftpEnabled) {
					if (user) ftpUser = user;
					if (savedPassword) ftpPassword = decrypt(savedPassword);

					// TODO: rewrite these to usable without shell
					const { stdout: password } = await executeCommand({
						command: `echo ${ftpPassword} | openssl passwd -1 -stdin`,
						shell: true
					});
					if (destinationDockerId) {
						try {
							await fs.stat(hostkeyDir);
						} catch (error) {
							await executeCommand({ command: `mkdir -p ${hostkeyDir}` });
						}
						if (!ftpHostKey) {
							await executeCommand({
								command: `ssh-keygen -t ed25519 -f ssh_host_ed25519_key -N "" -q -f ${hostkeyDir}/${id}.ed25519`
							});
							const { stdout: ftpHostKey } = await executeCommand({
								command: `cat ${hostkeyDir}/${id}.ed25519`
							});
							await prisma.wordpress.update({
								where: { serviceId: id },
								data: { ftpHostKey: encrypt(ftpHostKey) }
							});
						} else {
							await executeCommand({
								command: `echo "${decrypt(ftpHostKey)}" > ${hostkeyDir}/${id}.ed25519`,
								shell: true
							});
						}
						if (!ftpHostKeyPrivate) {
							await executeCommand({
								command: `ssh-keygen -t rsa -b 4096 -N "" -f ${hostkeyDir}/${id}.rsa`
							});
							const { stdout: ftpHostKeyPrivate } = await executeCommand({
								command: `cat ${hostkeyDir}/${id}.rsa`
							});
							await prisma.wordpress.update({
								where: { serviceId: id },
								data: { ftpHostKeyPrivate: encrypt(ftpHostKeyPrivate) }
							});
						} else {
							await executeCommand({
								command: `echo "${decrypt(ftpHostKeyPrivate)}" > ${hostkeyDir}/${id}.rsa`,
								shell: true
							});
						}

						await prisma.wordpress.update({
							where: { serviceId: id },
							data: {
								ftpPublicPort: publicPort,
								ftpUser: user ? undefined : ftpUser,
								ftpPassword: savedPassword ? undefined : encrypt(ftpPassword)
							}
						});

						try {
							const { found: isRunning } = await checkContainer({
								dockerId: destinationDocker.id,
								container: `${id}-ftp`
							});
							if (isRunning) {
								await executeCommand({
									dockerId: destinationDocker.id,
									command: `docker stop -t 0 ${id}-ftp && docker rm ${id}-ftp`,
									shell: true
								});
							}
						} catch (error) {}
						const volumes = [
							`${id}-wordpress-data:/home/${ftpUser}/wordpress`,
							`${
								isDev ? hostkeyDir : '/var/lib/docker/volumes/coolify-ssl-certs/_data/hostkeys'
							}/${id}.ed25519:/etc/ssh/ssh_host_ed25519_key`,
							`${
								isDev ? hostkeyDir : '/var/lib/docker/volumes/coolify-ssl-certs/_data/hostkeys'
							}/${id}.rsa:/etc/ssh/ssh_host_rsa_key`,
							`${
								isDev ? hostkeyDir : '/var/lib/docker/volumes/coolify-ssl-certs/_data/hostkeys'
							}/${id}.sh:/etc/sftp.d/chmod.sh`
						];

						const compose = {
							version: '3.8',
							services: {
								[`${id}-ftp`]: {
									image: `atmoz/sftp:alpine`,
									command: `'${ftpUser}:${password.replace('\n', '').replace(/\$/g, '$$$')}:e:33'`,
									extra_hosts: ['host.docker.internal:host-gateway'],
									container_name: `${id}-ftp`,
									volumes,
									networks: [network],
									depends_on: [],
									restart: 'always'
								}
							},
							networks: {
								[network]: {
									external: true
								}
							},
							volumes: {
								[`${id}-wordpress-data`]: {
									external: true,
									name: `${id}-wordpress-data`
								}
							}
						};
						await fs.writeFile(
							`${hostkeyDir}/${id}.sh`,
							`#!/bin/bash\nchmod 600 /etc/ssh/ssh_host_ed25519_key /etc/ssh/ssh_host_rsa_key\nuserdel -f xfs\nchown -R 33:33 /home/${ftpUser}/wordpress/`
						);
						await executeCommand({ command: `chmod +x ${hostkeyDir}/${id}.sh` });
						await fs.writeFile(`${hostkeyDir}/${id}-docker-compose.yml`, yaml.dump(compose));
						await executeCommand({
							dockerId: destinationDocker.id,
							command: `docker compose -f ${hostkeyDir}/${id}-docker-compose.yml up -d`
						});
					}
					return {
						publicPort,
						ftpUser,
						ftpPassword
					};
				} else {
					await prisma.wordpress.update({
						where: { serviceId: id },
						data: { ftpPublicPort: null }
					});
					try {
						await executeCommand({
							dockerId: destinationDocker.id,
							command: `docker stop -t 0 ${id}-ftp && docker rm ${id}-ftp`,
							shell: true
						});
					} catch (error) {
						//
					}
					await stopTcpHttpProxy(id, destinationDocker, ftpPublicPort);
				}
			} catch ({ status, message }) {
				throw message;
			} finally {
				try {
					await executeCommand({
						command: `rm -fr ${hostkeyDir}/${id}-docker-compose.yml ${hostkeyDir}/${id}.ed25519 ${hostkeyDir}/${id}.ed25519.pub ${hostkeyDir}/${id}.rsa ${hostkeyDir}/${id}.rsa.pub ${hostkeyDir}/${id}.sh`
					});
				} catch (error) {}
			}
		}),
	start: privateProcedure.input(z.object({ id: z.string() })).mutation(async ({ input, ctx }) => {
		const { id } = input;
		const teamId = ctx.user?.teamId;
		const service = await getServiceFromDB({ id, teamId });
		const arm = isARM(service.arch);
		const { type, destinationDockerId, destinationDocker, persistentStorage, exposePort } = service;

		const { workdir } = await createDirectories({ repository: type, buildId: id });
		const template: any = await parseAndFindServiceTemplates(service, workdir, true);
		const network = destinationDockerId && destinationDocker.network;
		const config = {};
		for (const s in template.services) {
			let newEnvironments = [];
			if (arm) {
				if (template.services[s]?.environmentArm?.length > 0) {
					for (const environment of template.services[s].environmentArm) {
						let [env, ...value] = environment.split('=');
						value = value.join('=');
						if (!value.startsWith('$$secret') && value !== '') {
							newEnvironments.push(`${env}=${value}`);
						}
					}
				}
			} else {
				if (template.services[s]?.environment?.length > 0) {
					for (const environment of template.services[s].environment) {
						let [env, ...value] = environment.split('=');
						value = value.join('=');
						if (!value.startsWith('$$secret') && value !== '') {
							newEnvironments.push(`${env}=${value}`);
						}
					}
				}
			}
			const secrets = await verifyAndDecryptServiceSecrets(id);
			for (const secret of secrets) {
				const { name, value } = secret;
				if (value) {
					const foundEnv = !!template.services[s].environment?.find((env) =>
						env.startsWith(`${name}=`)
					);
					const foundNewEnv = !!newEnvironments?.find((env) => env.startsWith(`${name}=`));
					if (foundEnv && !foundNewEnv) {
						newEnvironments.push(`${name}=${value}`);
					}
					if (!foundEnv && !foundNewEnv && s === id) {
						newEnvironments.push(`${name}=${value}`);
					}
				}
			}
			const customVolumes = await prisma.servicePersistentStorage.findMany({
				where: { serviceId: id }
			});
			let volumes = new Set();
			if (arm) {
				template.services[s]?.volumesArm &&
					template.services[s].volumesArm.length > 0 &&
					template.services[s].volumesArm.forEach((v) => volumes.add(v));
			} else {
				template.services[s]?.volumes &&
					template.services[s].volumes.length > 0 &&
					template.services[s].volumes.forEach((v) => volumes.add(v));
			}

			// Workaround: old plausible analytics service wrong volume id name
			if (service.type === 'plausibleanalytics' && service.plausibleAnalytics?.id) {
				let temp = Array.from(volumes);
				temp.forEach((a) => {
					const t = a.replace(service.id, service.plausibleAnalytics.id);
					volumes.delete(a);
					volumes.add(t);
				});
			}

			if (customVolumes.length > 0) {
				for (const customVolume of customVolumes) {
					const { volumeName, path, containerId } = customVolume;
					if (
						volumes &&
						volumes.size > 0 &&
						!volumes.has(`${volumeName}:${path}`) &&
						containerId === service
					) {
						volumes.add(`${volumeName}:${path}`);
					}
				}
			}
			let ports = [];
			if (template.services[s].proxy?.length > 0) {
				for (const proxy of template.services[s].proxy) {
					if (proxy.hostPort) {
						ports.push(`${proxy.hostPort}:${proxy.port}`);
					}
				}
			} else {
				if (template.services[s].ports?.length === 1) {
					for (const port of template.services[s].ports) {
						if (exposePort) {
							ports.push(`${exposePort}:${port}`);
						}
					}
				}
			}
			let image = template.services[s].image;
			if (arm && template.services[s].imageArm) {
				image = template.services[s].imageArm;
			}
			config[s] = {
				container_name: s,
				build: template.services[s].build || undefined,
				command: template.services[s].command,
				entrypoint: template.services[s]?.entrypoint,
				image,
				expose: template.services[s].ports,
				ports: ports.length > 0 ? ports : undefined,
				volumes: Array.from(volumes),
				environment: newEnvironments,
				depends_on: template.services[s]?.depends_on,
				ulimits: template.services[s]?.ulimits,
				cap_drop: template.services[s]?.cap_drop,
				cap_add: template.services[s]?.cap_add,
				labels: makeLabelForServices(type),
				...defaultComposeConfiguration(network)
			};
			// Generate files for builds
			if (template.services[s]?.files?.length > 0) {
				if (!config[s].build) {
					config[s].build = {
						context: workdir,
						dockerfile: `Dockerfile.${s}`
					};
				}
				let Dockerfile = `
                    FROM ${template.services[s].image}`;
				for (const file of template.services[s].files) {
					const { location, content } = file;
					const source = path.join(workdir, location);
					await fs.mkdir(path.dirname(source), { recursive: true });
					await fs.writeFile(source, content);
					Dockerfile += `
                        COPY .${location} ${location}`;
				}
				await fs.writeFile(`${workdir}/Dockerfile.${s}`, Dockerfile);
			}
		}
		const { volumeMounts } = persistentVolumes(id, persistentStorage, config);
		const composeFile = {
			version: '3.8',
			services: config,
			networks: {
				[network]: {
					external: true
				}
			},
			volumes: volumeMounts
		};
		const composeFileDestination = `${workdir}/docker-compose.yaml`;
		await fs.writeFile(composeFileDestination, yaml.dump(composeFile));
		// TODO: TODO!
		let fastify = null;
		await startServiceContainers(fastify, id, teamId, destinationDocker.id, composeFileDestination);

		// Workaround: Stop old minio proxies
		if (service.type === 'minio') {
			try {
				const { stdout: containers } = await executeCommand({
					dockerId: destinationDocker.id,
					command: `docker container ls -a --filter 'name=${id}-' --format {{.ID}}`
				});
				if (containers) {
					const containerArray = containers.split('\n');
					if (containerArray.length > 0) {
						for (const container of containerArray) {
							await executeCommand({
								dockerId: destinationDockerId,
								command: `docker stop -t 0 ${container}`
							});
							await executeCommand({
								dockerId: destinationDockerId,
								command: `docker rm --force ${container}`
							});
						}
					}
				}
			} catch (error) {}
			try {
				const { stdout: containers } = await executeCommand({
					dockerId: destinationDocker.id,
					command: `docker container ls -a --filter 'name=${id}-' --format {{.ID}}`
				});
				if (containers) {
					const containerArray = containers.split('\n');
					if (containerArray.length > 0) {
						for (const container of containerArray) {
							await executeCommand({
								dockerId: destinationDockerId,
								command: `docker stop -t 0 ${container}`
							});
							await executeCommand({
								dockerId: destinationDockerId,
								command: `docker rm --force ${container}`
							});
						}
					}
				}
			} catch (error) {}
		}
	}),
	stop: privateProcedure.input(z.object({ id: z.string() })).mutation(async ({ input, ctx }) => {
		const { id } = input;
		const teamId = ctx.user?.teamId;
		const { destinationDockerId } = await getServiceFromDB({ id, teamId });
		if (destinationDockerId) {
			const { stdout: containers } = await executeCommand({
				dockerId: destinationDockerId,
				command: `docker ps -a --filter 'label=com.docker.compose.project=${id}' --format {{.ID}}`
			});
			if (containers) {
				const containerArray = containers.split('\n');
				if (containerArray.length > 0) {
					for (const container of containerArray) {
						await executeCommand({
							dockerId: destinationDockerId,
							command: `docker stop -t 0 ${container}`
						});
						await executeCommand({
							dockerId: destinationDockerId,
							command: `docker rm --force ${container}`
						});
					}
				}
			}
			return {};
		}
	}),
	getServices: privateProcedure
		.input(z.object({ id: z.string() }))
		.query(async ({ input, ctx }) => {
			const { id } = input;
			const teamId = ctx.user?.teamId;
			const service = await getServiceFromDB({ id, teamId });
			if (!service) {
				throw { status: 404, message: 'Service not found.' };
			}
			let template = {};
			let tags = [];
			if (service.type) {
				template = await parseAndFindServiceTemplates(service);
				tags = await getTags(service.type);
			}
			return {
				success: true,
				data: {
					settings: await listSettings(),
					service,
					template,
					tags
				}
			};
		}),
	status: privateProcedure.input(z.object({ id: z.string() })).query(async ({ ctx, input }) => {
		const id = input.id;
		const teamId = ctx.user?.teamId;
		if (!teamId) {
			throw { status: 400, message: 'Team not found.' };
		}
		const service = await getServiceFromDB({ id, teamId });
		const { destinationDockerId } = service;
		let payload = {};
		if (destinationDockerId) {
			const { stdout: containers } = await executeCommand({
				dockerId: service.destinationDocker.id,
				command: `docker ps -a --filter "label=com.docker.compose.project=${id}" --format '{{json .}}'`
			});
			if (containers) {
				const containersArray = containers.trim().split('\n');
				if (containersArray.length > 0 && containersArray[0] !== '') {
					const templates = await getTemplates();
					let template = templates.find((t: { type: string }) => t.type === service.type);
					const templateStr = JSON.stringify(template);
					if (templateStr) {
						template = JSON.parse(templateStr.replaceAll('$$id', service.id));
					}
					for (const container of containersArray) {
						let isRunning = false;
						let isExited = false;
						let isRestarting = false;
						let isExcluded = false;
						const containerObj = JSON.parse(container);
						const exclude = template?.services[containerObj.Names]?.exclude;
						if (exclude) {
							payload[containerObj.Names] = {
								status: {
									isExcluded: true,
									isRunning: false,
									isExited: false,
									isRestarting: false
								}
							};
							continue;
						}

						const status = containerObj.State;
						if (status === 'running') {
							isRunning = true;
						}
						if (status === 'exited') {
							isExited = true;
						}
						if (status === 'restarting') {
							isRestarting = true;
						}
						payload[containerObj.Names] = {
							status: {
								isExcluded,
								isRunning,
								isExited,
								isRestarting
							}
						};
					}
				}
			}
		}
		return payload;
	}),
	cleanup: privateProcedure.query(async ({ ctx }) => {
		const teamId = ctx.user?.teamId;
		let services = await prisma.service.findMany({
			where: { teams: { some: { id: teamId === '0' ? undefined : teamId } } },
			include: { destinationDocker: true, teams: true }
		});
		for (const service of services) {
			if (!service.fqdn) {
				if (service.destinationDockerId) {
					const { stdout: containers } = await executeCommand({
						dockerId: service.destinationDockerId,
						command: `docker ps -a --filter 'label=com.docker.compose.project=${service.id}' --format {{.ID}}`
					});
					if (containers) {
						const containerArray = containers.split('\n');
						if (containerArray.length > 0) {
							for (const container of containerArray) {
								await executeCommand({
									dockerId: service.destinationDockerId,
									command: `docker stop -t 0 ${container}`
								});
								await executeCommand({
									dockerId: service.destinationDockerId,
									command: `docker rm --force ${container}`
								});
							}
						}
					}
				}
				await removeService({ id: service.id });
			}
		}
	}),
	delete: privateProcedure
		.input(z.object({ force: z.boolean(), id: z.string() }))
		.mutation(async ({ input }) => {
			// todo: check if user is allowed to delete service
			const { id } = input;
			await prisma.serviceSecret.deleteMany({ where: { serviceId: id } });
			await prisma.serviceSetting.deleteMany({ where: { serviceId: id } });
			await prisma.servicePersistentStorage.deleteMany({ where: { serviceId: id } });
			await prisma.meiliSearch.deleteMany({ where: { serviceId: id } });
			await prisma.fider.deleteMany({ where: { serviceId: id } });
			await prisma.ghost.deleteMany({ where: { serviceId: id } });
			await prisma.umami.deleteMany({ where: { serviceId: id } });
			await prisma.hasura.deleteMany({ where: { serviceId: id } });
			await prisma.plausibleAnalytics.deleteMany({ where: { serviceId: id } });
			await prisma.minio.deleteMany({ where: { serviceId: id } });
			await prisma.vscodeserver.deleteMany({ where: { serviceId: id } });
			await prisma.wordpress.deleteMany({ where: { serviceId: id } });
			await prisma.glitchTip.deleteMany({ where: { serviceId: id } });
			await prisma.moodle.deleteMany({ where: { serviceId: id } });
			await prisma.appwrite.deleteMany({ where: { serviceId: id } });
			await prisma.searxng.deleteMany({ where: { serviceId: id } });
			await prisma.weblate.deleteMany({ where: { serviceId: id } });
			await prisma.taiga.deleteMany({ where: { serviceId: id } });

			await prisma.service.delete({ where: { id } });
			return {};
		})
});

export async function getServiceFromDB({
	id,
	teamId
}: {
	id: string;
	teamId: string;
}): Promise<any> {
	const settings = await prisma.setting.findFirst();
	const body = await prisma.service.findFirst({
		where: { id, teams: { some: { id: teamId === '0' ? undefined : teamId } } },
		include: {
			destinationDocker: true,
			persistentStorage: true,
			serviceSecret: true,
			serviceSetting: true,
			wordpress: true,
			plausibleAnalytics: true
		}
	});
	if (!body) {
		return null;
	}
	// body.type = fixType(body.type);

	if (body?.serviceSecret.length > 0) {
		body.serviceSecret = body.serviceSecret.map((s) => {
			s.value = decrypt(s.value);
			return s;
		});
	}
	if (body.wordpress) {
		body.wordpress.ftpPassword = decrypt(body.wordpress.ftpPassword);
	}

	return { ...body, settings };
}
