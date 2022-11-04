import { parentPort } from 'node:worker_threads';
import crypto from 'crypto';
import fs from 'fs/promises';
import yaml from 'js-yaml';

import { copyBaseConfigurationFiles, makeLabelForStandaloneApplication, saveBuildLog, setDefaultConfiguration } from '../lib/buildPacks/common';
import { createDirectories, decrypt, defaultComposeConfiguration, executeDockerCmd, getDomain, prisma, decryptApplication } from '../lib/common';
import * as importers from '../lib/importers';
import * as buildpacks from '../lib/buildPacks';

(async () => {
	if (parentPort) {
		parentPort.on('message', async (message) => {
			if (message === 'error') throw new Error('oops');
			if (message === 'cancel') {
				parentPort.postMessage('cancelled');
				await prisma.$disconnect()
				process.exit(0);
			}
		});
		const pThrottle = await import('p-throttle')
		const throttle = pThrottle.default({
			limit: 1,
			interval: 2000
		});


		const th = throttle(async () => {
			try {
				const queuedBuilds = await prisma.build.findMany({ where: { status: { in: ['queued', 'running'] } }, orderBy: { createdAt: 'asc' } });
				const { concurrentBuilds } = await prisma.setting.findFirst({})
				if (queuedBuilds.length > 0) {
					parentPort.postMessage({ deploying: true });
					const concurrency = concurrentBuilds;
					const pAll = await import('p-all');
					const actions = []

					for (const queueBuild of queuedBuilds) {
						actions.push(async () => {
							let application = await prisma.application.findUnique({ where: { id: queueBuild.applicationId }, include: { destinationDocker: true, gitSource: { include: { githubApp: true, gitlabApp: true } }, persistentStorage: true, secrets: true, settings: true, teams: true } })
							let { id: buildId, type, sourceBranch = null, pullmergeRequestId = null, previewApplicationId = null, forceRebuild, sourceRepository = null } = queueBuild
							application = decryptApplication(application)
							const originalApplicationId = application.id
							if (pullmergeRequestId) {
								const previewApplications = await prisma.previewApplication.findMany({ where: { applicationId: originalApplicationId, pullmergeRequestId } })
								if (previewApplications.length > 0) {
									previewApplicationId = previewApplications[0].id
								}
							}
							const usableApplicationId = previewApplicationId || originalApplicationId
							try {
								if (queueBuild.status === 'running') {
									await saveBuildLog({ line: 'Building halted, restarting...', buildId, applicationId: application.id });
								}
								const {
									id: applicationId,
									name,
									destinationDocker,
									destinationDockerId,
									gitSource,
									configHash,
									fqdn,
									projectId,
									secrets,
									phpModules,
									settings,
									persistentStorage,
									pythonWSGI,
									pythonModule,
									pythonVariable,
									denoOptions,
									exposePort,
									baseImage,
									baseBuildImage,
									deploymentType,
								} = application
								let {
									branch,
									repository,
									buildPack,
									port,
									installCommand,
									buildCommand,
									startCommand,
									baseDirectory,
									publishDirectory,
									dockerFileLocation,
									dockerComposeConfiguration,
									denoMainFile
								} = application
								const currentHash = crypto
									.createHash('sha256')
									.update(
										JSON.stringify({
											pythonWSGI,
											pythonModule,
											pythonVariable,
											deploymentType,
											denoOptions,
											baseImage,
											baseBuildImage,
											buildPack,
											port,
											exposePort,
											installCommand,
											buildCommand,
											startCommand,
											secrets,
											branch,
											repository,
											fqdn
										})
									)
									.digest('hex');
								const { debug } = settings;
								let imageId = applicationId;
								let domain = getDomain(fqdn);
								const volumes =
									persistentStorage?.map((storage) => {
										return `${applicationId}${storage.path.replace(/\//gi, '-')}:${buildPack !== 'docker' ? '/app' : ''
											}${storage.path}`;
									}) || [];
								// Previews, we need to get the source branch and set subdomain
								if (pullmergeRequestId) {
									branch = sourceBranch;
									domain = `${pullmergeRequestId}.${domain}`;
									imageId = `${applicationId}-${pullmergeRequestId}`;
									repository = sourceRepository || repository;
								}

								try {
									dockerComposeConfiguration = JSON.parse(dockerComposeConfiguration)
								} catch (error) { }
								let deployNeeded = true;
								let destinationType;

								if (destinationDockerId) {
									destinationType = 'docker';
								}
								if (destinationType === 'docker') {
									await prisma.build.update({ where: { id: buildId }, data: { status: 'running' } });
									const { workdir, repodir } = await createDirectories({ repository, buildId });
									const configuration = await setDefaultConfiguration(application);

									buildPack = configuration.buildPack;
									port = configuration.port;
									installCommand = configuration.installCommand;
									startCommand = configuration.startCommand;
									buildCommand = configuration.buildCommand;
									publishDirectory = configuration.publishDirectory;
									baseDirectory = configuration.baseDirectory || '';
									dockerFileLocation = configuration.dockerFileLocation;
									denoMainFile = configuration.denoMainFile;
									const commit = await importers[gitSource.type]({
										applicationId,
										debug,
										workdir,
										repodir,
										githubAppId: gitSource.githubApp?.id,
										gitlabAppId: gitSource.gitlabApp?.id,
										customPort: gitSource.customPort,
										repository,
										branch,
										buildId,
										apiUrl: gitSource.apiUrl,
										htmlUrl: gitSource.htmlUrl,
										projectId,
										deployKeyId: gitSource.gitlabApp?.deployKeyId || null,
										privateSshKey: decrypt(gitSource.gitlabApp?.privateSshKey) || null,
										forPublic: gitSource.forPublic
									});
									if (!commit) {
										throw new Error('No commit found?');
									}
									let tag = commit.slice(0, 7);
									if (pullmergeRequestId) {
										tag = `${commit.slice(0, 7)}-${pullmergeRequestId}`;
									}

									try {
										await prisma.build.update({ where: { id: buildId }, data: { commit } });
									} catch (err) { }

									if (!pullmergeRequestId) {
										if (configHash !== currentHash) {
											deployNeeded = true;
											if (configHash) {
												await saveBuildLog({ line: 'Configuration changed.', buildId, applicationId });
											}
										} else {
											deployNeeded = false;
										}
									} else {
										deployNeeded = true;
									}

									let imageFound = false;
									try {
										await executeDockerCmd({
											dockerId: destinationDocker.id,
											command: `docker image inspect ${applicationId}:${tag}`
										})
										imageFound = true;
									} catch (error) {
										//
									}
									await copyBaseConfigurationFiles(buildPack, workdir, buildId, applicationId, baseImage);
									const labels = makeLabelForStandaloneApplication({
										applicationId,
										fqdn,
										name,
										type,
										pullmergeRequestId,
										buildPack,
										repository,
										branch,
										projectId,
										port: exposePort ? `${exposePort}:${port}` : port,
										commit,
										installCommand,
										buildCommand,
										startCommand,
										baseDirectory,
										publishDirectory
									});
									if (forceRebuild) deployNeeded = true
									if (!imageFound || deployNeeded) {
										if (buildpacks[buildPack])
											await buildpacks[buildPack]({
												dockerId: destinationDocker.id,
												network: destinationDocker.network,
												buildId,
												applicationId,
												domain,
												name,
												type,
												volumes,
												labels,
												pullmergeRequestId,
												buildPack,
												repository,
												branch,
												projectId,
												publishDirectory,
												debug,
												commit,
												tag,
												workdir,
												port: exposePort ? `${exposePort}:${port}` : port,
												installCommand,
												buildCommand,
												startCommand,
												baseDirectory,
												secrets,
												phpModules,
												pythonWSGI,
												pythonModule,
												pythonVariable,
												dockerFileLocation,
												dockerComposeConfiguration,
												denoMainFile,
												denoOptions,
												baseImage,
												baseBuildImage,
												deploymentType,
											});
										else {
											await saveBuildLog({ line: `Build pack ${buildPack} not found`, buildId, applicationId });
											throw new Error(`Build pack ${buildPack} not found.`);
										}
									} else {
										await saveBuildLog({ line: 'Build image already available - no rebuild required.', buildId, applicationId });
									}

									if (buildPack === 'compose') {
										try {
											await executeDockerCmd({
												dockerId: destinationDockerId,
												command: `docker ps -a --filter 'label=coolify.applicationId=${applicationId}' --format {{.ID}}|xargs -r -n 1 docker stop -t 0`
											})
											await executeDockerCmd({
												dockerId: destinationDockerId,
												command: `docker ps -a --filter 'label=coolify.applicationId=${applicationId}' --format {{.ID}}|xargs -r -n 1 docker rm --force`
											})
										} catch (error) {
											//
										}
										try {
											await executeDockerCmd({ debug, buildId, applicationId, dockerId: destinationDocker.id, command: `docker compose --project-directory ${workdir} up -d` })
											await saveBuildLog({ line: 'Deployment successful!', buildId, applicationId });
											await saveBuildLog({ line: 'Proxy will be updated shortly.', buildId, applicationId });
											await prisma.build.update({ where: { id: buildId }, data: { status: 'success' } });
											await prisma.application.update({
												where: { id: applicationId },
												data: { configHash: currentHash }
											});
										} catch (error) {
											await saveBuildLog({ line: error, buildId, applicationId });
											const foundBuild = await prisma.build.findUnique({ where: { id: buildId } })
											if (foundBuild) {
												await prisma.build.update({
													where: { id: buildId },
													data: {
														status: 'failed'
													}
												});
											}
											throw new Error(error);
										}

									} else {
										try {
											await executeDockerCmd({
												dockerId: destinationDockerId,
												command: `docker ps -a --filter 'label=com.docker.compose.service=${pullmergeRequestId ? imageId : applicationId}' --format {{.ID}}|xargs -r -n 1 docker stop -t 0`
											})
											await executeDockerCmd({
												dockerId: destinationDockerId,
												command: `docker ps -a --filter 'label=com.docker.compose.service=${pullmergeRequestId ? imageId : applicationId}' --format {{.ID}}|xargs -r -n 1 docker rm --force`
											})
										} catch (error) {
											//
										}
										const envs = [
											`PORT=${port}`
										];
										if (secrets.length > 0) {
											secrets.forEach((secret) => {
												if (pullmergeRequestId) {
													const isSecretFound = secrets.filter(s => s.name === secret.name && s.isPRMRSecret)
													if (isSecretFound.length > 0) {
														envs.push(`${secret.name}=${isSecretFound[0].value}`);
													} else {
														envs.push(`${secret.name}=${secret.value}`);
													}
												} else {
													if (!secret.isPRMRSecret) {
														envs.push(`${secret.name}=${secret.value}`);
													}
												}
											});
										}
										await fs.writeFile(`${workdir}/.env`, envs.join('\n'));

										let envFound = false;
										try {
											envFound = !!(await fs.stat(`${workdir}/.env`));
										} catch (error) {
											//
										}
										try {
											await saveBuildLog({ line: 'Deployment started.', buildId, applicationId });
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
													[imageId]: {
														image: `${applicationId}:${tag}`,
														container_name: imageId,
														volumes,
														env_file: envFound ? [`${workdir}/.env`] : [],
														labels,
														depends_on: [],
														expose: [port],
														...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
														...defaultComposeConfiguration(destinationDocker.network),
													}
												},
												networks: {
													[destinationDocker.network]: {
														external: true
													}
												},
												volumes: Object.assign({}, ...composeVolumes)
											};
											await fs.writeFile(`${workdir}/docker-compose.yml`, yaml.dump(composeFile));
											await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose --project-directory ${workdir} up -d` })
											await saveBuildLog({ line: 'Deployment successful!', buildId, applicationId });
										} catch (error) {
											await saveBuildLog({ line: error, buildId, applicationId });
											const foundBuild = await prisma.build.findUnique({ where: { id: buildId } })
											if (foundBuild) {
												await prisma.build.update({
													where: { id: buildId },
													data: {
														status: 'failed'
													}
												});
											}
											throw new Error(error);
										}
										await saveBuildLog({ line: 'Proxy will be updated shortly.', buildId, applicationId });
										await prisma.build.update({ where: { id: buildId }, data: { status: 'success' } });
										if (!pullmergeRequestId) await prisma.application.update({
											where: { id: applicationId },
											data: { configHash: currentHash }
										});
									}
								}
							}
							catch (error) {
								const foundBuild = await prisma.build.findUnique({ where: { id: buildId } })
								if (foundBuild) {
									await prisma.build.update({
										where: { id: buildId },
										data: {
											status: 'failed'
										}
									});
								}
								if (error !== 1) {
									await saveBuildLog({ line: error, buildId, applicationId: application.id });
								}
							}
						});
					}
					await pAll.default(actions, { concurrency })
				}
			} catch (error) {
				console.log(error)
			}
		})
		while (true) {
			await th()
		}
	} else process.exit(0);
})();
