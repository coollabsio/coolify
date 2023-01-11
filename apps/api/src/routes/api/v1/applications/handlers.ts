import cuid from 'cuid';
import crypto from 'node:crypto';
import jsonwebtoken from 'jsonwebtoken';
import { FastifyReply } from 'fastify';
import fs from 'fs/promises';
import yaml from 'js-yaml';
import csv from 'csvtojson';

import { day } from '../../../../lib/dayjs';
import {
	saveDockerRegistryCredentials,
	setDefaultBaseImage,
	setDefaultConfiguration
} from '../../../../lib/buildPacks/common';
import {
	checkDomainsIsValidInDNS,
	checkExposedPort,
	createDirectories,
	decrypt,
	defaultComposeConfiguration,
	encrypt,
	errorHandler,
	executeCommand,
	generateSecrets,
	generateSshKeyPair,
	getContainerUsage,
	getDomain,
	isDev,
	isDomainConfigured,
	listSettings,
	prisma,
	stopBuild,
	uniqueName
} from '../../../../lib/common';
import { checkContainer, formatLabelsOnDocker, removeContainer } from '../../../../lib/docker';

import type { FastifyRequest } from 'fastify';
import type {
	GetImages,
	CancelDeployment,
	CheckDNS,
	CheckRepository,
	DeleteApplication,
	DeleteSecret,
	DeleteStorage,
	GetApplicationLogs,
	GetBuildIdLogs,
	SaveApplication,
	SaveApplicationSettings,
	SaveApplicationSource,
	SaveDeployKey,
	SaveDestination,
	SaveSecret,
	SaveStorage,
	DeployApplication,
	CheckDomain,
	StopPreviewApplication,
	RestartPreviewApplication,
	GetBuilds,
	RestartApplication
} from './types';
import { OnlyId } from '../../../../types';

function filterObject(obj, callback) {
	return Object.fromEntries(Object.entries(obj).filter(([key, val]) => callback(val, key)));
}

export async function listApplications(request: FastifyRequest) {
	try {
		const { teamId } = request.user;
		const applications = await prisma.application.findMany({
			where: { teams: { some: { id: teamId === '0' ? undefined : teamId } } },
			include: { teams: true, destinationDocker: true, settings: true }
		});
		const settings = await prisma.setting.findFirst();
		return {
			applications,
			settings
		};
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function getImages(request: FastifyRequest<GetImages>) {
	try {
		const { buildPack, deploymentType } = request.body;
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

		return { baseImage, baseImages, baseBuildImage, baseBuildImages, publishDirectory, port };
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function cleanupUnconfiguredApplications(request: FastifyRequest<any>) {
	try {
		const teamId = request.user.teamId;
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
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function getApplicationStatus(request: FastifyRequest<OnlyId>) {
	try {
		const { id } = request.params;
		const { teamId } = request.user;
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
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function getApplication(request: FastifyRequest<OnlyId>) {
	try {
		const { id } = request.params;
		const { teamId } = request.user;
		const appId = process.env['COOLIFY_APP_ID'];
		const application: any = await getApplicationFromDB(id, teamId);
		const settings = await listSettings();
		return {
			application,
			appId,
			settings
		};
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function newApplication(request: FastifyRequest, reply: FastifyReply) {
	try {
		const name = uniqueName();
		const { teamId } = request.user;
		const { id } = await prisma.application.create({
			data: {
				name,
				teams: { connect: { id: teamId } },
				settings: { create: { debug: false, previews: false } }
			}
		});
		return reply.code(201).send({ id });
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
function decryptApplication(application: any) {
	if (application) {
		if (application?.gitSource?.githubApp?.clientSecret) {
			application.gitSource.githubApp.clientSecret =
				decrypt(application.gitSource.githubApp.clientSecret) || null;
		}
		if (application?.gitSource?.githubApp?.webhookSecret) {
			application.gitSource.githubApp.webhookSecret =
				decrypt(application.gitSource.githubApp.webhookSecret) || null;
		}
		if (application?.gitSource?.githubApp?.privateKey) {
			application.gitSource.githubApp.privateKey =
				decrypt(application.gitSource.githubApp.privateKey) || null;
		}
		if (application?.gitSource?.gitlabApp?.appSecret) {
			application.gitSource.gitlabApp.appSecret =
				decrypt(application.gitSource.gitlabApp.appSecret) || null;
		}
		if (application?.secrets.length > 0) {
			application.secrets = application.secrets.map((s: any) => {
				s.value = decrypt(s.value) || null;
				return s;
			});
		}

		return application;
	}
}
export async function getApplicationFromDB(id: string, teamId: string) {
	try {
		let application = await prisma.application.findFirst({
			where: { id, teams: { some: { id: teamId === '0' ? undefined : teamId } } },
			include: {
				destinationDocker: true,
				settings: true,
				gitSource: { include: { githubApp: true, gitlabApp: true } },
				secrets: true,
				persistentStorage: true,
				connectedDatabase: true,
				previewApplication: true,
				dockerRegistry: true
			}
		});
		if (!application) {
			throw { status: 404, message: 'Application not found.' };
		}
		application = decryptApplication(application);
		const buildPack = application?.buildPack || null;
		const { baseImage, baseBuildImage, baseBuildImages, baseImages } =
			setDefaultBaseImage(buildPack);

		// Set default build images
		if (!application.baseImage) {
			application.baseImage = baseImage;
		}
		if (!application.baseBuildImage) {
			application.baseBuildImage = baseBuildImage;
		}
		return { ...application, baseBuildImages, baseImages };
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function getApplicationFromDBWebhook(projectId: number, branch: string) {
	try {
		let applications = await prisma.application.findMany({
			where: { projectId, branch, settings: { autodeploy: true } },
			include: {
				destinationDocker: true,
				settings: true,
				gitSource: { include: { githubApp: true, gitlabApp: true } },
				secrets: true,
				persistentStorage: true,
				connectedDatabase: true
			}
		});
		if (applications.length === 0) {
			throw { status: 500, message: 'Application not configured.', type: 'webhook' };
		}
		applications = applications.map((application: any) => {
			application = decryptApplication(application);
			const { baseImage, baseBuildImage, baseBuildImages, baseImages } = setDefaultBaseImage(
				application.buildPack
			);

			// Set default build images
			if (!application.baseImage) {
				application.baseImage = baseImage;
			}
			if (!application.baseBuildImage) {
				application.baseBuildImage = baseBuildImage;
			}
			application.baseBuildImages = baseBuildImages;
			application.baseImages = baseImages;
			return application;
		});

		return applications;
	} catch ({ status, message, type }) {
		return errorHandler({ status, message, type });
	}
}
export async function saveApplication(
	request: FastifyRequest<SaveApplication>,
	reply: FastifyReply
) {
	try {
		const { id } = request.params;
		let {
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
		} = request.body;
		if (port) port = Number(port);
		if (exposePort) {
			exposePort = Number(exposePort);
		}
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
					dockerComposeFileLocation,
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
					dockerComposeFileLocation,
					dockerComposeConfiguration,
					simpleDockerfile,
					dockerRegistryImageName,
					...defaultConfiguration
				}
			});
		}

		return reply.code(201).send();
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function saveApplicationSettings(
	request: FastifyRequest<SaveApplicationSettings>,
	reply: FastifyReply
) {
	try {
		const { id } = request.params;
		const {
			debug,
			previews,
			dualCerts,
			autodeploy,
			branch,
			projectId,
			isBot,
			isDBBranching,
			isCustomSSL
		} = request.body;
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
		return reply.code(201).send();
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function stopPreviewApplication(
	request: FastifyRequest<StopPreviewApplication>,
	reply: FastifyReply
) {
	try {
		const { id } = request.params;
		const { pullmergeRequestId } = request.body;
		const { teamId } = request.user;
		const application: any = await getApplicationFromDB(id, teamId);
		if (application?.destinationDockerId) {
			const container = `${id}-${pullmergeRequestId}`;
			const { id: dockerId } = application.destinationDocker;
			const { found } = await checkContainer({ dockerId, container });
			if (found) {
				await removeContainer({ id: container, dockerId: application.destinationDocker.id });
			}
			await prisma.previewApplication.deleteMany({
				where: { applicationId: application.id, pullmergeRequestId }
			});
		}
		return reply.code(201).send();
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function restartApplication(
	request: FastifyRequest<RestartApplication>,
	reply: FastifyReply
) {
	try {
		const { id } = request.params;
		const { imageId = null } = request.body;
		const { teamId } = request.user;
		let application: any = await getApplicationFromDB(id, teamId);
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

			let envs = [];
			if (secrets.length > 0) {
				envs = [...envs, ...generateSecrets(secrets, pullmergeRequestId, false, port)];
			}

			const { workdir } = await createDirectories({ repository, buildId });
			const labels = [];
			let image = null;
			if (imageId) {
				image = imageId;
			} else {
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
						environment: envs,
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
			return reply.code(201).send();
		}
		throw { status: 500, message: 'Application cannot be restarted.' };
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function stopApplication(request: FastifyRequest<OnlyId>, reply: FastifyReply) {
	try {
		const { id } = request.params;
		const { teamId } = request.user;
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
		return reply.code(201).send();
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function deleteApplication(
	request: FastifyRequest<DeleteApplication>,
	reply: FastifyReply
) {
	try {
		const { id } = request.params;
		const { force } = request.body;

		const { teamId } = request.user;
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
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function checkDomain(request: FastifyRequest<CheckDomain>) {
	try {
		const { id } = request.params;
		const { domain } = request.query;
		const {
			fqdn,
			settings: { dualCerts }
		} = await prisma.application.findUnique({ where: { id }, include: { settings: true } });
		// TODO: Disabled this because it is having problems with remote docker engines.
		// return await checkDomainsIsValidInDNS({ hostname: domain, fqdn, dualCerts });
		return {};
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function checkDNS(request: FastifyRequest<CheckDNS>) {
	try {
		const { id } = request.params;
		let { exposePort, fqdn, forceSave, dualCerts } = request.body;
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
		// TODO: Disabled this because it is having problems with remote docker engines.
		// if (isDNSCheckEnabled && !isDev && !forceSave) {
		// 	let hostname = request.hostname.split(':')[0];
		// 	if (remoteEngine) hostname = remoteIpAddress;
		// 	return await checkDomainsIsValidInDNS({ hostname, fqdn, dualCerts });
		// }
		return {};
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function getUsage(request) {
	try {
		const { id } = request.params;
		const teamId = request.user?.teamId;
		let usage = {};

		const application: any = await getApplicationFromDB(id, teamId);
		if (application.destinationDockerId) {
			[usage] = await Promise.all([getContainerUsage(application.destinationDocker.id, id)]);
		}
		return {
			usage
		};
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function getDockerImages(request) {
	try {
		const { id } = request.params;
		const teamId = request.user?.teamId;
		const application: any = await getApplicationFromDB(id, teamId);
		let imagesAvailables = [];
		try {
			const { stdout } = await executeCommand({
				dockerId: application.destinationDocker.id,
				command: `docker images --format '{{.Repository}}#{{.Tag}}#{{.CreatedAt}}'`
			});
			const { stdout: runningImage } = await executeCommand({
				dockerId: application.destinationDocker.id,
				command: `docker ps -a --filter 'label=com.docker.compose.service=${id}' --format {{.Image}}`
			});
			const images = stdout
				.trim()
				.split('\n')
				.filter((image) => image.includes(id) && !image.includes('-cache'));
			for (const image of images) {
				const [repository, tag, createdAt] = image.split('#');
				if (tag.includes('-')) {
					continue;
				}
				const [year, time] = createdAt.split(' ');
				imagesAvailables.push({
					repository,
					tag,
					createdAt: day(year + time).unix()
				});
			}

			imagesAvailables = imagesAvailables.sort((a, b) => b.tag - a.tag);

			return {
				imagesAvailables,
				runningImage
			};
		} catch (error) {
			console.log(error);
			return {
				imagesAvailables
			};
		}
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function getUsageByContainer(request) {
	try {
		const { id, containerId } = request.params;
		const teamId = request.user?.teamId;
		let usage = {};

		const application: any = await getApplicationFromDB(id, teamId);
		if (application.destinationDockerId) {
			[usage] = await Promise.all([
				getContainerUsage(application.destinationDocker.id, containerId)
			]);
		}
		return {
			usage
		};
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function deployApplication(request: FastifyRequest<DeployApplication>) {
	try {
		const { id } = request.params;
		const teamId = request.user?.teamId;
		const { pullmergeRequestId = null, branch, forceRebuild } = request.body;
		const buildId = cuid();
		const application = await getApplicationFromDB(id, teamId);
		if (application) {
			if (!application?.configHash) {
				const configHash = crypto
					.createHash('sha256')
					.update(
						JSON.stringify({
							buildPack: application.buildPack,
							port: application.port,
							exposePort: application.exposePort,
							installCommand: application.installCommand,
							buildCommand: application.buildCommand,
							startCommand: application.startCommand
						})
					)
					.digest('hex');
				await prisma.application.update({ where: { id }, data: { configHash } });
			}
			await prisma.application.update({ where: { id }, data: { updatedAt: new Date() } });
			if (application.gitSourceId) {
				await prisma.build.create({
					data: {
						id: buildId,
						applicationId: id,
						sourceBranch: branch,
						branch: application.branch,
						pullmergeRequestId: pullmergeRequestId?.toString(),
						forceRebuild,
						destinationDockerId: application.destinationDocker?.id,
						gitSourceId: application.gitSource?.id,
						githubAppId: application.gitSource?.githubApp?.id,
						gitlabAppId: application.gitSource?.gitlabApp?.id,
						status: 'queued',
						type: pullmergeRequestId
							? application.gitSource?.githubApp?.id
								? 'manual_pr'
								: 'manual_mr'
							: 'manual'
					}
				});
			} else {
				await prisma.build.create({
					data: {
						id: buildId,
						applicationId: id,
						branch: 'latest',
						forceRebuild,
						destinationDockerId: application.destinationDocker?.id,
						status: 'queued',
						type: 'manual'
					}
				});
			}

			return {
				buildId
			};
		}
		throw { status: 500, message: 'Application not found!' };
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function saveApplicationSource(
	request: FastifyRequest<SaveApplicationSource>,
	reply: FastifyReply
) {
	try {
		const { id } = request.params;
		const { gitSourceId, forPublic, type, simpleDockerfile } = request.body;
		if (forPublic) {
			const publicGit = await prisma.gitSource.findFirst({ where: { type, forPublic } });
			await prisma.application.update({
				where: { id },
				data: { gitSource: { connect: { id: publicGit.id } } }
			});
		}
		if (simpleDockerfile) {
			await prisma.application.update({
				where: { id },
				data: { simpleDockerfile, settings: { update: { autodeploy: false } } }
			});
		}
		if (gitSourceId) {
			await prisma.application.update({
				where: { id },
				data: { gitSource: { connect: { id: gitSourceId } } }
			});
		}

		return reply.code(201).send();
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function getGitHubToken(request: FastifyRequest<OnlyId>, reply: FastifyReply) {
	try {
		const { default: got } = await import('got');
		const { id } = request.params;
		const { teamId } = request.user;
		const application: any = await getApplicationFromDB(id, teamId);
		const payload = {
			iat: Math.round(new Date().getTime() / 1000),
			exp: Math.round(new Date().getTime() / 1000 + 60),
			iss: application.gitSource.githubApp.appId
		};
		const githubToken = jsonwebtoken.sign(payload, application.gitSource.githubApp.privateKey, {
			algorithm: 'RS256'
		});
		const { token } = await got
			.post(
				`${application.gitSource.apiUrl}/app/installations/${application.gitSource.githubApp.installationId}/access_tokens`,
				{
					headers: {
						Authorization: `Bearer ${githubToken}`
					}
				}
			)
			.json();
		return reply.code(201).send({
			token
		});
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function checkRepository(request: FastifyRequest<CheckRepository>) {
	try {
		const { id } = request.params;
		const { repository, branch } = request.query;
		const application = await prisma.application.findUnique({
			where: { id },
			include: { gitSource: true }
		});
		const found = await prisma.application.findFirst({
			where: {
				branch,
				repository,
				gitSource: { type: application.gitSource.type },
				id: { not: id }
			}
		});
		return {
			used: found ? true : false
		};
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function saveRepository(request, reply) {
	try {
		const { id } = request.params;
		let {
			repository,
			branch,
			projectId,
			autodeploy,
			webhookToken,
			isPublicRepository = false
		} = request.body;

		repository = repository.toLowerCase();

		projectId = Number(projectId);
		if (webhookToken) {
			await prisma.application.update({
				where: { id },
				data: {
					repository,
					branch,
					projectId,
					gitSource: {
						update: {
							gitlabApp: { update: { webhookToken: webhookToken ? webhookToken : undefined } }
						}
					},
					settings: { update: { autodeploy, isPublicRepository } }
				}
			});
		} else {
			await prisma.application.update({
				where: { id },
				data: {
					repository,
					branch,
					projectId,
					settings: { update: { autodeploy, isPublicRepository } }
				}
			});
		}
		// if (!isPublicRepository) {
		//     const isDouble = await checkDoubleBranch(branch, projectId);
		//     if (isDouble) {
		//         await prisma.applicationSettings.updateMany({ where: { application: { branch, projectId } }, data: { autodeploy: false, isPublicRepository } })
		//     }
		// }
		return reply.code(201).send();
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function saveDestination(
	request: FastifyRequest<SaveDestination>,
	reply: FastifyReply
) {
	try {
		const { id } = request.params;
		const { destinationId } = request.body;
		await prisma.application.update({
			where: { id },
			data: { destinationDocker: { connect: { id: destinationId } } }
		});
		return reply.code(201).send();
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function getBuildPack(request) {
	try {
		const { id } = request.params;
		const teamId = request.user?.teamId;
		const application: any = await getApplicationFromDB(id, teamId);
		return {
			type: application.gitSource?.type || 'dockerRegistry',
			projectId: application.projectId,
			repository: application.repository,
			branch: application.branch,
			apiUrl: application.gitSource?.apiUrl || null,
			isPublicRepository: application.settings.isPublicRepository
		};
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function saveRegistry(request, reply) {
	try {
		const { id } = request.params;
		const { registryId } = request.body;
		await prisma.application.update({
			where: { id },
			data: { dockerRegistry: { connect: { id: registryId } } }
		});
		return reply.code(201).send();
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function saveBuildPack(request, reply) {
	try {
		const { id } = request.params;
		const { buildPack } = request.body;
		const { baseImage, baseBuildImage } = setDefaultBaseImage(buildPack);
		await prisma.application.update({
			where: { id },
			data: { buildPack, baseImage, baseBuildImage }
		});
		return reply.code(201).send();
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function saveConnectedDatabase(request, reply) {
	try {
		const { id } = request.params;
		const { databaseId, type } = request.body;
		await prisma.application.update({
			where: { id },
			data: {
				connectedDatabase: {
					upsert: {
						create: { database: { connect: { id: databaseId } }, hostedDatabaseType: type },
						update: { database: { connect: { id: databaseId } }, hostedDatabaseType: type }
					}
				}
			}
		});
		return reply.code(201).send();
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function getSecrets(request: FastifyRequest<OnlyId>) {
	try {
		const { id } = request.params;

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
			previewSecrets: previewSecrets.sort((a, b) => {
				return ('' + a.name).localeCompare(b.name);
			}),
			secrets: secrets.sort((a, b) => {
				return ('' + a.name).localeCompare(b.name);
			})
		};
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function updatePreviewSecret(
	request: FastifyRequest<SaveSecret>,
	reply: FastifyReply
) {
	try {
		const { id } = request.params;
		let { name, value } = request.body;
		if (value) {
			value = encrypt(value.trim());
		} else {
			value = '';
		}
		await prisma.secret.updateMany({
			where: { applicationId: id, name, isPRMRSecret: true },
			data: { value }
		});
		return reply.code(201).send();
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function updateSecret(request: FastifyRequest<SaveSecret>, reply: FastifyReply) {
	try {
		const { id } = request.params;
		const { name, value, isBuildSecret = undefined } = request.body;
		await prisma.secret.updateMany({
			where: { applicationId: id, name },
			data: { value: encrypt(value.trim()), isBuildSecret }
		});
		return reply.code(201).send();
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function saveSecret(request: FastifyRequest<SaveSecret>, reply: FastifyReply) {
	try {
		const { id } = request.params;
		const { name, value, isBuildSecret = false } = request.body;
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
		return reply.code(201).send();
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function deleteSecret(request: FastifyRequest<DeleteSecret>) {
	try {
		const { id } = request.params;
		const { name } = request.body;
		await prisma.secret.deleteMany({ where: { applicationId: id, name } });
		return {};
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function getStorages(request: FastifyRequest<OnlyId>) {
	try {
		const { id } = request.params;
		const persistentStorages = await prisma.applicationPersistentStorage.findMany({
			where: { applicationId: id }
		});
		return {
			persistentStorages
		};
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function saveStorage(request: FastifyRequest<SaveStorage>, reply: FastifyReply) {
	try {
		const { id } = request.params;
		const { path, newStorage, storageId } = request.body;

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
		return reply.code(201).send();
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function deleteStorage(request: FastifyRequest<DeleteStorage>) {
	try {
		const { id } = request.params;
		const { path } = request.body;
		await prisma.applicationPersistentStorage.deleteMany({ where: { applicationId: id, path } });
		return {};
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function restartPreview(
	request: FastifyRequest<RestartPreviewApplication>,
	reply: FastifyReply
) {
	try {
		const { id, pullmergeRequestId } = request.params;
		const { teamId } = request.user;
		let application: any = await getApplicationFromDB(id, teamId);
		if (application?.destinationDockerId) {
			const buildId = cuid();
			const { id: dockerId, network } = application.destinationDocker;
			const {
				secrets,
				port,
				repository,
				persistentStorage,
				id: applicationId,
				buildPack,
				exposePort
			} = application;

			let envs = [];
			if (secrets.length > 0) {
				envs = [...envs, ...generateSecrets(secrets, pullmergeRequestId, false, port)];
			}
			const { workdir } = await createDirectories({ repository, buildId });
			const labels = [];
			let image = null;
			const { stdout: container } = await executeCommand({
				dockerId,
				command: `docker container ls --filter 'label=com.docker.compose.service=${id}-${pullmergeRequestId}' --format '{{json .}}'`
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
			let imageFound = false;
			try {
				await executeCommand({
					dockerId,
					command: `docker image inspect ${image}`
				});
				imageFound = true;
			} catch (error) {
				//
			}
			if (!imageFound) {
				throw { status: 500, message: 'Image not found, cannot restart application.' };
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
					[`${applicationId}-${pullmergeRequestId}`]: {
						image,
						container_name: `${applicationId}-${pullmergeRequestId}`,
						volumes,
						environment: envs,
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
			await executeCommand({ dockerId, command: `docker stop -t 0 ${id}-${pullmergeRequestId}` });
			await executeCommand({ dockerId, command: `docker rm ${id}-${pullmergeRequestId}` });
			await executeCommand({
				dockerId,
				command: `docker compose --project-directory ${workdir} up -d`
			});
			return reply.code(201).send();
		}
		throw { status: 500, message: 'Application cannot be restarted.' };
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function getPreviewStatus(request: FastifyRequest<RestartPreviewApplication>) {
	try {
		const { id, pullmergeRequestId } = request.params;
		const { teamId } = request.user;
		let isRunning = false;
		let isExited = false;
		let isRestarting = false;
		let isBuilding = false;
		const application: any = await getApplicationFromDB(id, teamId);
		if (application?.destinationDockerId) {
			const status = await checkContainer({
				dockerId: application.destinationDocker.id,
				container: `${id}-${pullmergeRequestId}`
			});
			if (status?.found) {
				isRunning = status.status.isRunning;
				isExited = status.status.isExited;
				isRestarting = status.status.isRestarting;
			}
			const building = await prisma.build.findMany({
				where: { applicationId: id, pullmergeRequestId, status: { in: ['queued', 'running'] } }
			});
			isBuilding = building.length > 0;
		}
		return {
			isBuilding,
			isRunning,
			isRestarting,
			isExited
		};
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function loadPreviews(request: FastifyRequest<OnlyId>) {
	try {
		const { id } = request.params;
		const application = await prisma.application.findUnique({
			where: { id },
			include: { destinationDocker: true }
		});
		const { stdout } = await executeCommand({
			dockerId: application.destinationDocker.id,
			command: `docker container ls --filter 'name=${id}-' --format "{{json .}}"`
		});
		if (stdout === '') {
			throw { status: 500, message: 'No previews found.' };
		}
		const containers = formatLabelsOnDocker(stdout).filter(
			(container) =>
				container.Labels['coolify.configuration'] &&
				container.Labels['coolify.type'] === 'standalone-application'
		);

		const jsonContainers = containers
			.map((container) =>
				JSON.parse(Buffer.from(container.Labels['coolify.configuration'], 'base64').toString())
			)
			.filter((container) => {
				return container.pullmergeRequestId && container.applicationId === id;
			});
		for (const container of jsonContainers) {
			const found = await prisma.previewApplication.findMany({
				where: {
					applicationId: container.applicationId,
					pullmergeRequestId: container.pullmergeRequestId
				}
			});
			if (found.length === 0) {
				await prisma.previewApplication.create({
					data: {
						pullmergeRequestId: container.pullmergeRequestId,
						sourceBranch: container.branch,
						customDomain: container.fqdn,
						application: { connect: { id: container.applicationId } }
					}
				});
			}
		}
		return {
			previews: await prisma.previewApplication.findMany({ where: { applicationId: id } })
		};
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function getPreviews(request: FastifyRequest<OnlyId>) {
	try {
		const { id } = request.params;
		const { teamId } = request.user;
		let secrets = await prisma.secret.findMany({
			where: { applicationId: id },
			orderBy: { createdAt: 'desc' }
		});
		secrets = secrets.map((secret) => {
			secret.value = decrypt(secret.value);
			return secret;
		});

		const applicationSecrets = secrets.filter((secret) => !secret.isPRMRSecret);
		const PRMRSecrets = secrets.filter((secret) => secret.isPRMRSecret);
		return {
			applicationSecrets: applicationSecrets.sort((a, b) => {
				return ('' + a.name).localeCompare(b.name);
			}),
			PRMRSecrets: PRMRSecrets.sort((a, b) => {
				return ('' + a.name).localeCompare(b.name);
			})
		};
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function getApplicationLogs(request: FastifyRequest<GetApplicationLogs>) {
	try {
		const { id, containerId } = request.params;
		let { since = 0 } = request.query;
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
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function getBuilds(request: FastifyRequest<GetBuilds>) {
	try {
		const { id } = request.params;
		let { buildId, skip = 0 } = request.query;
		if (typeof skip !== 'number') {
			skip = Number(skip);
		}

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
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function getBuildIdLogs(request: FastifyRequest<GetBuildIdLogs>) {
	try {
		// TODO: Fluentbit could still hold the logs, so we need to check if the logs are done
		const { buildId, id } = request.params;
		let { sequence = 0 } = request.query;
		if (typeof sequence !== 'number') {
			sequence = Number(sequence);
		}
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
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function getGitLabSSHKey(request: FastifyRequest<OnlyId>) {
	try {
		const { id } = request.params;
		const application = await prisma.application.findUnique({
			where: { id },
			include: { gitSource: { include: { gitlabApp: true } } }
		});
		return { publicKey: application.gitSource.gitlabApp.publicSshKey };
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function saveGitLabSSHKey(request: FastifyRequest<OnlyId>, reply: FastifyReply) {
	try {
		const { id } = request.params;
		const application = await prisma.application.findUnique({
			where: { id },
			include: { gitSource: { include: { gitlabApp: true } } }
		});
		if (!application.gitSource?.gitlabApp?.privateSshKey) {
			const keys = await generateSshKeyPair();
			const encryptedPrivateKey = encrypt(keys.privateKey);
			await prisma.gitlabApp.update({
				where: { id: application.gitSource.gitlabApp.id },
				data: { privateSshKey: encryptedPrivateKey, publicSshKey: keys.publicKey }
			});
			return reply.code(201).send({ publicKey: keys.publicKey });
		}
		return { message: 'SSH key already exists' };
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function saveDeployKey(request: FastifyRequest<SaveDeployKey>, reply: FastifyReply) {
	try {
		const { id } = request.params;
		let { deployKeyId } = request.body;

		deployKeyId = Number(deployKeyId);
		const application = await prisma.application.findUnique({
			where: { id },
			include: { gitSource: { include: { gitlabApp: true } } }
		});
		await prisma.gitlabApp.update({
			where: { id: application.gitSource.gitlabApp.id },
			data: { deployKeyId }
		});
		return reply.code(201).send();
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function cancelDeployment(
	request: FastifyRequest<CancelDeployment>,
	reply: FastifyReply
) {
	try {
		const { buildId, applicationId } = request.body;
		if (!buildId) {
			throw { status: 500, message: 'buildId is required' };
		}
		await stopBuild(buildId, applicationId);
		return reply.code(201).send();
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}

export async function createdBranchDatabase(
	database: any,
	baseDatabaseBranch: string,
	pullmergeRequestId: string
) {
	try {
		if (!baseDatabaseBranch) return;
		const { id, type, destinationDockerId, rootUser, rootUserPassword, dbUser } = database;
		if (destinationDockerId) {
			if (type === 'postgresql') {
				const decryptedRootUserPassword = decrypt(rootUserPassword);
				await executeCommand({
					dockerId: destinationDockerId,
					command: `docker exec ${id} pg_dump -d "postgresql://postgres:${decryptedRootUserPassword}@${id}:5432/${baseDatabaseBranch}" --encoding=UTF8 --schema-only -f /tmp/${baseDatabaseBranch}.dump`
				});
				await executeCommand({
					dockerId: destinationDockerId,
					command: `docker exec ${id} psql postgresql://postgres:${decryptedRootUserPassword}@${id}:5432 -c "CREATE DATABASE branch_${pullmergeRequestId}"`
				});
				await executeCommand({
					dockerId: destinationDockerId,
					command: `docker exec ${id} psql -d "postgresql://postgres:${decryptedRootUserPassword}@${id}:5432/branch_${pullmergeRequestId}" -f /tmp/${baseDatabaseBranch}.dump`
				});
				await executeCommand({
					dockerId: destinationDockerId,
					command: `docker exec ${id} psql postgresql://postgres:${decryptedRootUserPassword}@${id}:5432 -c "ALTER DATABASE branch_${pullmergeRequestId} OWNER TO ${dbUser}"`
				});
			}
		}
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
export async function removeBranchDatabase(database: any, pullmergeRequestId: string) {
	try {
		const { id, type, destinationDockerId, rootUser, rootUserPassword } = database;
		if (destinationDockerId) {
			if (type === 'postgresql') {
				const decryptedRootUserPassword = decrypt(rootUserPassword);
				// Terminate all connections to the database
				await executeCommand({
					dockerId: destinationDockerId,
					command: `docker exec ${id} psql postgresql://postgres:${decryptedRootUserPassword}@${id}:5432 -c "SELECT pg_terminate_backend(pg_stat_activity.pid) FROM pg_stat_activity WHERE pg_stat_activity.datname = 'branch_${pullmergeRequestId}' AND pid <> pg_backend_pid();"`
				});

				await executeCommand({
					dockerId: destinationDockerId,
					command: `docker exec ${id} psql postgresql://postgres:${decryptedRootUserPassword}@${id}:5432 -c "DROP DATABASE branch_${pullmergeRequestId}"`
				});
			}
		}
	} catch ({ status, message }) {
		return errorHandler({ status, message });
	}
}
