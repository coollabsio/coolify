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
import { deployApplication, generateConfigHash, getApplicationFromDB } from './lib';
import cuid from 'cuid';
import { createDirectories, saveDockerRegistryCredentials } from '../../../lib/common';

export const applicationsRouter = router({
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
				id: z.string()
			})
		)
		.mutation(async ({ ctx, input }) => {
			const { id } = input;
			const teamId = ctx.user?.teamId;

			// const buildId = await deployApplication(id, teamId);
			return {
				// buildId
			};
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
			const envs = [`PORT=${port}`];

			if (secrets.length > 0) {
				secrets.forEach((secret) => {
					if (pullmergeRequestId) {
						const isSecretFound = secrets.filter((s) => s.name === secret.name && s.isPRMRSecret);
						if (isSecretFound.length > 0) {
							envs.push(`${secret.name}='${isSecretFound[0].value}'`);
						} else {
							envs.push(`${secret.name}='${secret.value}'`);
						}
					} else {
						if (!secret.isPRMRSecret) {
							envs.push(`${secret.name}='${secret.value}'`);
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
