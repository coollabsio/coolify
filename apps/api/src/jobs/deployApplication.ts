import { parentPort } from 'node:worker_threads';
import crypto from 'crypto';
import fs from 'fs/promises';
import yaml from 'js-yaml';

import { copyBaseConfigurationFiles, makeLabelForStandaloneApplication, saveBuildLog, setDefaultConfiguration } from '../lib/buildPacks/common';
import { asyncExecShell,  createDirectories, decrypt, getDomain, prisma } from '../lib/common';
import { dockerInstance, getEngine } from '../lib/docker';
import * as importers from '../lib/importers';
import * as buildpacks from '../lib/buildPacks';

(async () => {
	if (parentPort) {
		const concurrency = 1
		const PQueue = await import('p-queue');
		const queue = new PQueue.default({ concurrency });
		parentPort.on('message', async (message) => {
			if (parentPort) {
				if (message === 'error') throw new Error('oops');
				if (message === 'cancel') {
					parentPort.postMessage('cancelled');
					return;
				}
				if (message === 'status:autoUpdater') {
					parentPort.postMessage({ size: queue.size, pending: queue.pending, caller: 'autoUpdater' });
					return;
				}
				if (message === 'status:cleanupStorage') {
					parentPort.postMessage({ size: queue.size, pending: queue.pending, caller: 'cleanupStorage' });
					return;
				}

				await queue.add(async () => {
					const {
						id: applicationId,
						repository,
						name,
						destinationDocker,
						destinationDockerId,
						gitSource,
						build_id: buildId,
						configHash,
						fqdn,
						projectId,
						secrets,
						phpModules,
						type,
						pullmergeRequestId = null,
						sourceBranch = null,
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
					} = message
					let {
						branch,
						buildPack,
						port,
						installCommand,
						buildCommand,
						startCommand,
						baseDirectory,
						publishDirectory,
						dockerFileLocation,
						denoMainFile
					} = message
					try {
						const { debug } = settings;
						if (concurrency === 1) {
							await prisma.build.updateMany({
								where: {
									status: { in: ['queued', 'running'] },
									id: { not: buildId },
									applicationId,
									createdAt: { lt: new Date(new Date().getTime() - 10 * 1000) }
								},
								data: { status: 'failed' }
							});
						}
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
						}

						let deployNeeded = true;
						let destinationType;

						if (destinationDockerId) {
							destinationType = 'docker';
						}
						if (destinationType === 'docker') {
							const docker = dockerInstance({ destinationDocker });
							const host = getEngine(destinationDocker.engine);

							await prisma.build.update({ where: { id: buildId }, data: { status: 'running' } });
							const { workdir, repodir } = await createDirectories({ repository, buildId });
							const configuration = await setDefaultConfiguration(message);

							buildPack = configuration.buildPack;
							port = configuration.port;
							installCommand = configuration.installCommand;
							startCommand = configuration.startCommand;
							buildCommand = configuration.buildCommand;
							publishDirectory = configuration.publishDirectory;
							baseDirectory = configuration.baseDirectory;
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
								privateSshKey: decrypt(gitSource.gitlabApp?.privateSshKey) || null
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
							} catch (err) {
								console.log(err);
							}
							if (!pullmergeRequestId) {
								const currentHash = crypto
									//@ts-ignore
									.createHash('sha256')
									.update(
										JSON.stringify({
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

								if (configHash !== currentHash) {
									await prisma.application.update({
										where: { id: applicationId },
										data: { configHash: currentHash }
									});
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
							const image = await docker.engine.getImage(`${applicationId}:${tag}`);
							let imageFound = false;
							try {
								await image.inspect();
								imageFound = true;
							} catch (error) {
								//
							}
							if (!imageFound || deployNeeded) {
								await copyBaseConfigurationFiles(buildPack, workdir, buildId, applicationId, baseImage);
								if (buildpacks[buildPack])
									await buildpacks[buildPack]({
										buildId,
										applicationId,
										domain,
										name,
										type,
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
										docker,
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
										denoMainFile,
										denoOptions,
										baseImage,
										baseBuildImage,
										deploymentType
									});
								else {
									await saveBuildLog({ line: `Build pack ${buildPack} not found`, buildId, applicationId });
									throw new Error(`Build pack ${buildPack} not found.`);
								}
							} else {
								await saveBuildLog({ line: 'Build image already available - no rebuild required.', buildId, applicationId });
							}
							try {
								await asyncExecShell(`DOCKER_HOST=${host} docker stop -t 0 ${imageId}`);
								await asyncExecShell(`DOCKER_HOST=${host} docker rm ${imageId}`);
							} catch (error) {
								//
							}
							const envs = [];
							if (secrets.length > 0) {
								secrets.forEach((secret) => {
									if (pullmergeRequestId) {
										if (secret.isPRMRSecret) {
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
											networks: [docker.network],
											labels,
											depends_on: [],
											restart: 'always',
											...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
											// logging: {
											// 	driver: 'fluentd',
											// },
											deploy: {
												restart_policy: {
													condition: 'on-failure',
													delay: '5s',
													max_attempts: 3,
													window: '120s'
												}
											}
										}
									},
									networks: {
										[docker.network]: {
											external: true
										}
									},
									volumes: Object.assign({}, ...composeVolumes)
								};
								await fs.writeFile(`${workdir}/docker-compose.yml`, yaml.dump(composeFile));
								await asyncExecShell(
									`DOCKER_HOST=${host} docker compose --project-directory ${workdir} up -d`
								);
								await saveBuildLog({ line: 'Deployment successful!', buildId, applicationId });
							} catch (error) {
								await saveBuildLog({ line: error, buildId, applicationId });
								await prisma.build.update({
									where: { id: message.build_id },
									data: { status: 'failed' }
								});
								throw new Error(error);
							}
							await saveBuildLog({ line: 'Proxy will be updated shortly.', buildId, applicationId });
							await prisma.build.update({ where: { id: message.build_id }, data: { status: 'success' } });
						}

					}
					catch (error) {
						await prisma.build.update({
							where: { id: message.build_id },
							data: { status: 'failed' }
						});
						await saveBuildLog({ line: error, buildId, applicationId });
					} finally {
						await prisma.$disconnect();
					}
				});
				await prisma.$disconnect();
			}
		});
	} else process.exit(0);
})();
