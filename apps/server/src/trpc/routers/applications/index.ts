import { z } from 'zod';
import fs from 'fs/promises';
import yaml from 'js-yaml';
import { privateProcedure, router } from '../../trpc';
import { prisma } from '../../../prisma';
import { executeCommand } from '../../../lib/executeCommand';
import {
	checkContainer,
	defaultComposeConfiguration,
	formatLabelsOnDocker,
	removeContainer
} from '../../../lib/docker';
import {
	deployApplication,
	generateConfigHash,
	getApplicationFromDB,
	setDefaultBaseImage
} from './lib';
import cuid from 'cuid';
import {
	checkDomainsIsValidInDNS,
	checkExposedPort,
	cleanupDB,
	createDirectories,
	decrypt,
	encrypt,
	getDomain,
	isDev,
	isDomainConfigured,
	saveDockerRegistryCredentials,
	setDefaultConfiguration
} from '../../../lib/common';
import { day } from '../../../lib/dayjs';
import csv from 'csvtojson';

export const applicationsRouter = router({
	resetQueue: privateProcedure.mutation(async ({ ctx }) => {
		const teamId = ctx.user.teamId;
		if (teamId === '0') {
			await prisma.build.updateMany({
				where: { status: { in: ['queued', 'running'] } },
				data: { status: 'canceled' }
			});
			// scheduler.workers.get("deployApplication").postMessage("cancel");
		}
	}),
	cancelBuild: privateProcedure
		.input(
			z.object({
				buildId: z.string(),
				applicationId: z.string()
			})
		)
		.mutation(async ({ input }) => {
			const { buildId, applicationId } = input;
			let count = 0;
			await new Promise<void>(async (resolve, reject) => {
				const { destinationDockerId, status } = await prisma.build.findFirst({
					where: { id: buildId }
				});
				const { id: dockerId } = await prisma.destinationDocker.findFirst({
					where: { id: destinationDockerId }
				});
				const interval = setInterval(async () => {
					try {
						if (status === 'failed' || status === 'canceled') {
							clearInterval(interval);
							return resolve();
						}
						if (count > 15) {
							clearInterval(interval);
							// if (scheduler.workers.has('deployApplication')) {
							// 	scheduler.workers.get('deployApplication').postMessage('cancel');
							// }
							await cleanupDB(buildId, applicationId);
							return reject(new Error('Canceled.'));
						}
						const { stdout: buildContainers } = await executeCommand({
							dockerId,
							command: `docker container ls --filter "label=coolify.buildId=${buildId}" --format '{{json .}}'`
						});
						if (buildContainers) {
							const containersArray = buildContainers.trim().split('\n');
							for (const container of containersArray) {
								const containerObj = JSON.parse(container);
								const id = containerObj.ID;
								if (!containerObj.Names.startsWith(`${applicationId} `)) {
									await removeContainer({ id, dockerId });
									clearInterval(interval);
									// if (scheduler.workers.has('deployApplication')) {
									// 	scheduler.workers.get('deployApplication').postMessage('cancel');
									// }
									await cleanupDB(buildId, applicationId);
									return resolve();
								}
							}
						}
						count++;
					} catch (error) {}
				}, 100);
			});
		}),
	getBuildLogs: privateProcedure
		.input(
			z.object({
				id: z.string(),
				buildId: z.string(),
				sequence: z.number()
			})
		)
		.query(async ({ input }) => {
			let { id, buildId, sequence } = input;
			let file = `/app/logs/${id}_buildlog_${buildId}.csv`;
			if (isDev) {
				file = `${process.cwd()}/../../logs/${id}_buildlog_${buildId}.csv`;
			}
			const data = await prisma.build.findFirst({ where: { id: buildId } });
			const createdAt = day(data.createdAt).utc();
			try {
				await fs.stat(file);
			} catch (error) {
				let logs = await prisma.buildLog.findMany({
					where: { buildId, time: { gt: sequence } },
					orderBy: { time: 'asc' }
				});
				const data = await prisma.build.findFirst({ where: { id: buildId } });
				const createdAt = day(data.createdAt).utc();
				return {
					logs: logs.map((log) => {
						log.time = Number(log.time);
						return log;
					}),
					fromDb: true,
					took: day().diff(createdAt) / 1000,
					status: data?.status || 'queued'
				};
			}
			let fileLogs = (await fs.readFile(file)).toString();
			let decryptedLogs = await csv({ noheader: true }).fromString(fileLogs);
			let logs = decryptedLogs
				.map((log) => {
					const parsed = {
						time: log['field1'],
						line: decrypt(log['field2'] + '","' + log['field3'])
					};
					return parsed;
				})
				.filter((log) => log.time > sequence);
			return {
				logs,
				fromDb: false,
				took: day().diff(createdAt) / 1000,
				status: data?.status || 'queued'
			};
		}),
	getBuilds: privateProcedure
		.input(
			z.object({
				id: z.string(),
				buildId: z.string().optional(),
				skip: z.number()
			})
		)
		.query(async ({ input }) => {
			let { id, buildId, skip } = input;
			let builds = [];
			const buildCount = await prisma.build.count({ where: { applicationId: id } });
			if (buildId) {
				builds = await prisma.build.findMany({ where: { applicationId: id, id: buildId } });
			} else {
				builds = await prisma.build.findMany({
					where: { applicationId: id },
					orderBy: { createdAt: 'desc' },
					take: 5 + skip
				});
			}
			builds = builds.map((build) => {
				if (build.status === 'running') {
					build.elapsed = (day().utc().diff(day(build.createdAt)) / 1000).toFixed(0);
				}
				return build;
			});
			return {
				builds,
				buildCount
			};
		}),
	loadLogs: privateProcedure
		.input(
			z.object({
				id: z.string(),
				containerId: z.string(),
				since: z.number()
			})
		)
		.query(async ({ input }) => {
			let { id, containerId, since } = input;
			if (since !== 0) {
				since = day(since).unix();
			}
			const {
				destinationDockerId,
				destinationDocker: { id: dockerId }
			} = await prisma.application.findUnique({
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
					const logs = stripLogsStderr.concat(stripLogsStdout);
					const sortedLogs = logs.sort((a, b) =>
						day(a.split(' ')[0]).isAfter(day(b.split(' ')[0])) ? 1 : -1
					);
					return { logs: sortedLogs };
					// }
				} catch (error) {
					const { statusCode, stderr } = error;
					if (stderr.startsWith('Error: No such container')) {
						return { logs: [], noContainer: true };
					}
					if (statusCode === 404) {
						return {
							logs: []
						};
					}
				}
			}
			return {
				message: 'No logs found.'
			};
		}),
	getStorages: privateProcedure
		.input(
			z.object({
				id: z.string()
			})
		)
		.query(async ({ input }) => {
			const { id } = input;
			const persistentStorages = await prisma.applicationPersistentStorage.findMany({
				where: { applicationId: id }
			});

			return {
				success: true,
				data: {
					persistentStorages
				}
			};
		}),
	deleteStorage: privateProcedure
		.input(
			z.object({
				id: z.string(),
				path: z.string()
			})
		)
		.mutation(async ({ input }) => {
			const { id, path } = input;
			await prisma.applicationPersistentStorage.deleteMany({ where: { applicationId: id, path } });
		}),
	updateStorage: privateProcedure
		.input(
			z.object({
				id: z.string(),
				path: z.string(),
				storageId: z.string(),
				newStorage: z.boolean().optional().default(false)
			})
		)
		.mutation(async ({ input }) => {
			const { id, path, newStorage, storageId } = input;
			if (newStorage) {
				await prisma.applicationPersistentStorage.create({
					data: { path, application: { connect: { id } } }
				});
			} else {
				await prisma.applicationPersistentStorage.update({
					where: { id: storageId },
					data: { path }
				});
			}
		}),
	deleteSecret: privateProcedure
		.input(
			z.object({
				id: z.string(),
				name: z.string()
			})
		)
		.mutation(async ({ input }) => {
			const { id, name } = input;
			await prisma.secret.deleteMany({ where: { applicationId: id, name } });
		}),
	updateSecret: privateProcedure
		.input(
			z.object({
				id: z.string(),
				name: z.string(),
				value: z.string(),
				isBuildSecret: z.boolean().optional().default(false),
				isPreview: z.boolean().optional().default(false)
			})
		)
		.mutation(async ({ input }) => {
			const { id, name, value, isBuildSecret, isPreview } = input;
			console.log({ isBuildSecret });
			await prisma.secret.updateMany({
				where: { applicationId: id, name, isPRMRSecret: isPreview },
				data: { value: encrypt(value.trim()), isBuildSecret }
			});
		}),
	newSecret: privateProcedure
		.input(
			z.object({
				id: z.string(),
				name: z.string(),
				value: z.string(),
				isBuildSecret: z.boolean().optional().default(false)
			})
		)
		.mutation(async ({ input }) => {
			const { id, name, value, isBuildSecret } = input;
			const found = await prisma.secret.findMany({ where: { applicationId: id, name } });
			if (found.length > 0) {
				throw { message: 'Secret already exists.' };
			}
			await prisma.secret.create({
				data: {
					name,
					value: encrypt(value.trim()),
					isBuildSecret,
					isPRMRSecret: false,
					application: { connect: { id } }
				}
			});
			await prisma.secret.create({
				data: {
					name,
					value: encrypt(value.trim()),
					isBuildSecret,
					isPRMRSecret: true,
					application: { connect: { id } }
				}
			});
		}),
	getSecrets: privateProcedure
		.input(
			z.object({
				id: z.string()
			})
		)
		.query(async ({ input }) => {
			const { id } = input;
			let secrets = await prisma.secret.findMany({
				where: { applicationId: id, isPRMRSecret: false },
				orderBy: { createdAt: 'asc' }
			});
			let previewSecrets = await prisma.secret.findMany({
				where: { applicationId: id, isPRMRSecret: true },
				orderBy: { createdAt: 'asc' }
			});

			secrets = secrets.map((secret) => {
				secret.value = decrypt(secret.value);
				return secret;
			});
			previewSecrets = previewSecrets.map((secret) => {
				secret.value = decrypt(secret.value);
				return secret;
			});

			return {
				success: true,
				data: {
					previewSecrets: previewSecrets.sort((a, b) => {
						return ('' + a.name).localeCompare(b.name);
					}),
					secrets: secrets.sort((a, b) => {
						return ('' + a.name).localeCompare(b.name);
					})
				}
			};
		}),
	checkDomain: privateProcedure
		.input(
			z.object({
				id: z.string(),
				domain: z.string()
			})
		)
		.query(async ({ input, ctx }) => {
			const { id, domain } = input;
			const {
				fqdn,
				settings: { dualCerts }
			} = await prisma.application.findUnique({ where: { id }, include: { settings: true } });
			return await checkDomainsIsValidInDNS({ hostname: domain, fqdn, dualCerts });
		}),

	checkDNS: privateProcedure
		.input(
			z.object({
				id: z.string(),
				fqdn: z.string(),
				forceSave: z.boolean(),
				dualCerts: z.boolean(),
				exposePort: z.number().nullable().optional()
			})
		)
		.mutation(async ({ input, ctx }) => {
			let { id, exposePort, fqdn, forceSave, dualCerts } = input;
			if (!fqdn) {
				return {};
			} else {
				fqdn = fqdn.toLowerCase();
			}
			if (exposePort) exposePort = Number(exposePort);

			const {
				destinationDocker: { engine, remoteIpAddress, remoteEngine },
				exposePort: configuredPort
			} = await prisma.application.findUnique({
				where: { id },
				include: { destinationDocker: true }
			});
			const { isDNSCheckEnabled } = await prisma.setting.findFirst({});

			const found = await isDomainConfigured({ id, fqdn, remoteIpAddress });
			if (found) {
				throw {
					status: 500,
					message: `Domain ${getDomain(fqdn).replace('www.', '')} is already in use!`
				};
			}
			if (exposePort)
				await checkExposedPort({
					id,
					configuredPort,
					exposePort,
					engine,
					remoteEngine,
					remoteIpAddress
				});
			if (isDNSCheckEnabled && !isDev && !forceSave) {
				let hostname = ctx.hostname.split(':')[0];
				if (remoteEngine) hostname = remoteIpAddress;
				return await checkDomainsIsValidInDNS({ hostname, fqdn, dualCerts });
			}
		}),
	saveSettings: privateProcedure
		.input(
			z.object({
				id: z.string(),
				previews: z.boolean().optional(),
				debug: z.boolean().optional(),
				dualCerts: z.boolean().optional(),
				isBot: z.boolean().optional(),
				autodeploy: z.boolean().optional(),
				isDBBranching: z.boolean().optional(),
				isCustomSSL: z.boolean().optional()
			})
		)
		.mutation(async ({ ctx, input }) => {
			const { id, debug, previews, dualCerts, autodeploy, isBot, isDBBranching, isCustomSSL } =
				input;
			await prisma.application.update({
				where: { id },
				data: {
					fqdn: isBot ? null : undefined,
					settings: {
						update: { debug, previews, dualCerts, autodeploy, isBot, isDBBranching, isCustomSSL }
					}
				},
				include: { destinationDocker: true }
			});
		}),
	getImages: privateProcedure
		.input(z.object({ buildPack: z.string(), deploymentType: z.string().nullable() }))
		.query(async ({ ctx, input }) => {
			const { buildPack, deploymentType } = input;
			let publishDirectory = undefined;
			let port = undefined;
			const { baseImage, baseBuildImage, baseBuildImages, baseImages } = setDefaultBaseImage(
				buildPack,
				deploymentType
			);
			if (buildPack === 'nextjs') {
				if (deploymentType === 'static') {
					publishDirectory = 'out';
					port = '80';
				} else {
					publishDirectory = '';
					port = '3000';
				}
			}
			if (buildPack === 'nuxtjs') {
				if (deploymentType === 'static') {
					publishDirectory = 'dist';
					port = '80';
				} else {
					publishDirectory = '';
					port = '3000';
				}
			}
			return {
				success: true,
				data: { baseImage, baseImages, baseBuildImage, baseBuildImages, publishDirectory, port }
			};
		}),
	getApplicationById: privateProcedure
		.input(z.object({ id: z.string() }))
		.query(async ({ ctx, input }) => {
			const id: string = input.id;
			const teamId = ctx.user?.teamId;
			if (!teamId) {
				throw { status: 400, message: 'Team not found.' };
			}
			const application = await getApplicationFromDB(id, teamId);
			return {
				success: true,
				data: { ...application }
			};
		}),
	save: privateProcedure
		.input(
			z.object({
				id: z.string(),
				name: z.string(),
				buildPack: z.string(),
				fqdn: z.string().nullable().optional(),
				port: z.number(),
				exposePort: z.number().nullable().optional(),
				installCommand: z.string(),
				buildCommand: z.string(),
				startCommand: z.string(),
				baseDirectory: z.string().nullable().optional(),
				publishDirectory: z.string().nullable().optional(),
				pythonWSGI: z.string().nullable().optional(),
				pythonModule: z.string().nullable().optional(),
				pythonVariable: z.string().nullable().optional(),
				dockerFileLocation: z.string(),
				denoMainFile: z.string().nullable().optional(),
				denoOptions: z.string().nullable().optional(),
				gitCommitHash: z.string(),
				baseImage: z.string(),
				baseBuildImage: z.string(),
				deploymentType: z.string().nullable().optional(),
				baseDatabaseBranch: z.string().nullable().optional(),
				dockerComposeFile: z.string().nullable().optional(),
				dockerComposeFileLocation: z.string().nullable().optional(),
				dockerComposeConfiguration: z.string().nullable().optional(),
				simpleDockerfile: z.string().nullable().optional(),
				dockerRegistryImageName: z.string().nullable().optional()
			})
		)
		.mutation(async ({ input }) => {
			let {
				id,
				name,
				buildPack,
				fqdn,
				port,
				exposePort,
				installCommand,
				buildCommand,
				startCommand,
				baseDirectory,
				publishDirectory,
				pythonWSGI,
				pythonModule,
				pythonVariable,
				dockerFileLocation,
				denoMainFile,
				denoOptions,
				gitCommitHash,
				baseImage,
				baseBuildImage,
				deploymentType,
				baseDatabaseBranch,
				dockerComposeFile,
				dockerComposeFileLocation,
				dockerComposeConfiguration,
				simpleDockerfile,
				dockerRegistryImageName
			} = input;
			const {
				destinationDocker: { engine, remoteEngine, remoteIpAddress },
				exposePort: configuredPort
			} = await prisma.application.findUnique({
				where: { id },
				include: { destinationDocker: true }
			});
			if (exposePort)
				await checkExposedPort({
					id,
					configuredPort,
					exposePort,
					engine,
					remoteEngine,
					remoteIpAddress
				});
			if (denoOptions) denoOptions = denoOptions.trim();
			const defaultConfiguration = await setDefaultConfiguration({
				buildPack,
				port,
				installCommand,
				startCommand,
				buildCommand,
				publishDirectory,
				baseDirectory,
				dockerFileLocation,
				dockerComposeFileLocation,
				denoMainFile
			});
			if (baseDatabaseBranch) {
				await prisma.application.update({
					where: { id },
					data: {
						name,
						fqdn,
						exposePort,
						pythonWSGI,
						pythonModule,
						pythonVariable,
						denoOptions,
						baseImage,
						gitCommitHash,
						baseBuildImage,
						deploymentType,
						dockerComposeFile,
						dockerComposeConfiguration,
						simpleDockerfile,
						dockerRegistryImageName,
						...defaultConfiguration,
						connectedDatabase: { update: { hostedDatabaseDBName: baseDatabaseBranch } }
					}
				});
			} else {
				await prisma.application.update({
					where: { id },
					data: {
						name,
						fqdn,
						exposePort,
						pythonWSGI,
						pythonModule,
						gitCommitHash,
						pythonVariable,
						denoOptions,
						baseImage,
						baseBuildImage,
						deploymentType,
						dockerComposeFile,
						dockerComposeConfiguration,
						simpleDockerfile,
						dockerRegistryImageName,
						...defaultConfiguration
					}
				});
			}
		}),
	status: privateProcedure.input(z.object({ id: z.string() })).query(async ({ ctx, input }) => {
		const id: string = input.id;
		const teamId = ctx.user?.teamId;
		if (!teamId) {
			throw { status: 400, message: 'Team not found.' };
		}
		let payload = [];
		const application: any = await getApplicationFromDB(id, teamId);
		if (application?.destinationDockerId) {
			if (application.buildPack === 'compose') {
				const { stdout: containers } = await executeCommand({
					dockerId: application.destinationDocker.id,
					command: `docker ps -a --filter "label=coolify.applicationId=${id}" --format '{{json .}}'`
				});
				const containersArray = containers.trim().split('\n');
				if (containersArray.length > 0 && containersArray[0] !== '') {
					for (const container of containersArray) {
						let isRunning = false;
						let isExited = false;
						let isRestarting = false;
						const containerObj = JSON.parse(container);
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
						payload.push({
							name: containerObj.Names,
							status: {
								isRunning,
								isExited,
								isRestarting
							}
						});
					}
				}
			} else {
				let isRunning = false;
				let isExited = false;
				let isRestarting = false;
				const status = await checkContainer({
					dockerId: application.destinationDocker.id,
					container: id
				});
				if (status?.found) {
					isRunning = status.status.isRunning;
					isExited = status.status.isExited;
					isRestarting = status.status.isRestarting;
					payload.push({
						name: id,
						status: {
							isRunning,
							isExited,
							isRestarting
						}
					});
				}
			}
		}
		return payload;
	}),
	cleanup: privateProcedure.query(async ({ ctx }) => {
		const teamId = ctx.user?.teamId;
		let applications = await prisma.application.findMany({
			where: { teams: { some: { id: teamId === '0' ? undefined : teamId } } },
			include: { settings: true, destinationDocker: true, teams: true }
		});
		for (const application of applications) {
			if (
				!application.buildPack ||
				!application.destinationDockerId ||
				!application.branch ||
				(!application.settings?.isBot && !application?.fqdn)
			) {
				if (application?.destinationDockerId && application.destinationDocker?.network) {
					const { stdout: containers } = await executeCommand({
						dockerId: application.destinationDocker.id,
						command: `docker ps -a --filter network=${application.destinationDocker.network} --filter name=${application.id} --format '{{json .}}'`
					});
					if (containers) {
						const containersArray = containers.trim().split('\n');
						for (const container of containersArray) {
							const containerObj = JSON.parse(container);
							const id = containerObj.ID;
							await removeContainer({ id, dockerId: application.destinationDocker.id });
						}
					}
				}
				await prisma.applicationSettings.deleteMany({ where: { applicationId: application.id } });
				await prisma.buildLog.deleteMany({ where: { applicationId: application.id } });
				await prisma.build.deleteMany({ where: { applicationId: application.id } });
				await prisma.secret.deleteMany({ where: { applicationId: application.id } });
				await prisma.applicationPersistentStorage.deleteMany({
					where: { applicationId: application.id }
				});
				await prisma.applicationConnectedDatabase.deleteMany({
					where: { applicationId: application.id }
				});
				await prisma.application.deleteMany({ where: { id: application.id } });
			}
		}
		return {};
	}),
	stop: privateProcedure.input(z.object({ id: z.string() })).mutation(async ({ ctx, input }) => {
		const { id } = input;
		const teamId = ctx.user?.teamId;
		const application: any = await getApplicationFromDB(id, teamId);
		if (application?.destinationDockerId) {
			const { id: dockerId } = application.destinationDocker;
			if (application.buildPack === 'compose') {
				const { stdout: containers } = await executeCommand({
					dockerId: application.destinationDocker.id,
					command: `docker ps -a --filter "label=coolify.applicationId=${id}" --format '{{json .}}'`
				});
				const containersArray = containers.trim().split('\n');
				if (containersArray.length > 0 && containersArray[0] !== '') {
					for (const container of containersArray) {
						const containerObj = JSON.parse(container);
						await removeContainer({
							id: containerObj.ID,
							dockerId: application.destinationDocker.id
						});
					}
				}
				return;
			}
			const { found } = await checkContainer({ dockerId, container: id });
			if (found) {
				await removeContainer({ id, dockerId: application.destinationDocker.id });
			}
		}
		return {};
	}),
	restart: privateProcedure.input(z.object({ id: z.string() })).mutation(async ({ ctx, input }) => {
		const { id } = input;
		const teamId = ctx.user?.teamId;
		let application = await getApplicationFromDB(id, teamId);
		if (application?.destinationDockerId) {
			const buildId = cuid();
			const { id: dockerId, network } = application.destinationDocker;
			const {
				dockerRegistry,
				secrets,
				pullmergeRequestId,
				port,
				repository,
				persistentStorage,
				id: applicationId,
				buildPack,
				exposePort
			} = application;
			let location = null;
			const labels = [];
			let image = null;
			const envs = [`PORT=${port}`, 'NODE_ENV=production'];

			if (secrets.length > 0) {
				secrets.forEach((secret) => {
					if (pullmergeRequestId) {
						const isSecretFound = secrets.filter((s) => s.name === secret.name && s.isPRMRSecret);
						if (isSecretFound.length > 0) {
							if (isSecretFound[0].value.includes('\\n') || isSecretFound[0].value.includes("'")) {
								envs.push(`${secret.name}=${isSecretFound[0].value}`);
							} else {
								envs.push(`${secret.name}='${isSecretFound[0].value}'`);
							}
						} else {
							if (secret.value.includes('\\n') || secret.value.includes("'")) {
								envs.push(`${secret.name}=${secret.value}`);
							} else {
								envs.push(`${secret.name}='${secret.value}'`);
							}
						}
					} else {
						if (!secret.isPRMRSecret) {
							if (secret.value.includes('\\n') || secret.value.includes("'")) {
								envs.push(`${secret.name}=${secret.value}`);
							} else {
								envs.push(`${secret.name}='${secret.value}'`);
							}
						}
					}
				});
			}
			const { workdir } = await createDirectories({ repository, buildId });

			const { stdout: container } = await executeCommand({
				dockerId,
				command: `docker container ls --filter 'label=com.docker.compose.service=${id}' --format '{{json .}}'`
			});
			const containersArray = container.trim().split('\n');
			for (const container of containersArray) {
				const containerObj = formatLabelsOnDocker(container);
				image = containerObj[0].Image;
				Object.keys(containerObj[0].Labels).forEach(function (key) {
					if (key.startsWith('coolify')) {
						labels.push(`${key}=${containerObj[0].Labels[key]}`);
					}
				});
			}
			if (dockerRegistry) {
				const { url, username, password } = dockerRegistry;
				location = await saveDockerRegistryCredentials({ url, username, password, workdir });
			}

			let imageFoundLocally = false;
			try {
				await executeCommand({
					dockerId,
					command: `docker image inspect ${image}`
				});
				imageFoundLocally = true;
			} catch (error) {
				//
			}
			let imageFoundRemotely = false;
			try {
				await executeCommand({
					dockerId,
					command: `docker ${location ? `--config ${location}` : ''} pull ${image}`
				});
				imageFoundRemotely = true;
			} catch (error) {
				//
			}

			if (!imageFoundLocally && !imageFoundRemotely) {
				throw { status: 500, message: 'Image not found, cannot restart application.' };
			}
			await fs.writeFile(`${workdir}/.env`, envs.join('\n'));

			let envFound = false;
			try {
				envFound = !!(await fs.stat(`${workdir}/.env`));
			} catch (error) {
				//
			}
			const volumes =
				persistentStorage?.map((storage) => {
					return `${applicationId}${storage.path.replace(/\//gi, '-')}:${
						buildPack !== 'docker' ? '/app' : ''
					}${storage.path}`;
				}) || [];
			const composeVolumes = volumes.map((volume) => {
				return {
					[`${volume.split(':')[0]}`]: {
						name: volume.split(':')[0]
					}
				};
			});
			const composeFile = {
				version: '3.8',
				services: {
					[applicationId]: {
						image,
						container_name: applicationId,
						volumes,
						env_file: envFound ? [`${workdir}/.env`] : [],
						labels,
						depends_on: [],
						expose: [port],
						...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
						...defaultComposeConfiguration(network)
					}
				},
				networks: {
					[network]: {
						external: true
					}
				},
				volumes: Object.assign({}, ...composeVolumes)
			};
			await fs.writeFile(`${workdir}/docker-compose.yml`, yaml.dump(composeFile));
			try {
				await executeCommand({ dockerId, command: `docker stop -t 0 ${id}` });
				await executeCommand({ dockerId, command: `docker rm ${id}` });
			} catch (error) {
				//
			}

			await executeCommand({
				dockerId,
				command: `docker compose --project-directory ${workdir} up -d`
			});
		}
		return {};
	}),
	deploy: privateProcedure
		.input(
			z.object({
				id: z.string()
			})
		)
		.mutation(async ({ ctx, input }) => {
			const { id } = input;
			const teamId = ctx.user?.teamId;
			const buildId = await deployApplication(id, teamId);
			return {
				buildId
			};
		}),
	forceRedeploy: privateProcedure
		.input(
			z.object({
				id: z.string()
			})
		)
		.mutation(async ({ ctx, input }) => {
			const { id } = input;
			const teamId = ctx.user?.teamId;
			const buildId = await deployApplication(id, teamId, true);
			return {
				buildId
			};
		}),
	delete: privateProcedure
		.input(z.object({ force: z.boolean(), id: z.string() }))
		.mutation(async ({ ctx, input }) => {
			const { id, force } = input;
			const teamId = ctx.user?.teamId;
			const application = await prisma.application.findUnique({
				where: { id },
				include: { destinationDocker: true }
			});
			if (!force && application?.destinationDockerId && application.destinationDocker?.network) {
				const { stdout: containers } = await executeCommand({
					dockerId: application.destinationDocker.id,
					command: `docker ps -a --filter network=${application.destinationDocker.network} --filter name=${id} --format '{{json .}}'`
				});
				if (containers) {
					const containersArray = containers.trim().split('\n');
					for (const container of containersArray) {
						const containerObj = JSON.parse(container);
						const id = containerObj.ID;
						await removeContainer({ id, dockerId: application.destinationDocker.id });
					}
				}
			}
			await prisma.applicationSettings.deleteMany({ where: { application: { id } } });
			await prisma.buildLog.deleteMany({ where: { applicationId: id } });
			await prisma.build.deleteMany({ where: { applicationId: id } });
			await prisma.secret.deleteMany({ where: { applicationId: id } });
			await prisma.applicationPersistentStorage.deleteMany({ where: { applicationId: id } });
			await prisma.applicationConnectedDatabase.deleteMany({ where: { applicationId: id } });
			if (teamId === '0') {
				await prisma.application.deleteMany({ where: { id } });
			} else {
				await prisma.application.deleteMany({ where: { id, teams: { some: { id: teamId } } } });
			}
			return {};
		})
});
