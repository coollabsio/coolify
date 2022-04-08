import crypto from 'crypto';
import fs from 'fs/promises';
import * as buildpacks from '../buildPacks';
import * as importers from '../importers';
import { dockerInstance } from '../docker';
import {
	asyncExecShell,
	asyncSleep,
	createDirectories,
	getDomain,
	getEngine,
	saveBuildLog
} from '../common';
import * as db from '$lib/database';
import { decrypt } from '$lib/crypto';
import { sentry } from '$lib/common';
import {
	copyBaseConfigurationFiles,
	makeLabelForStandaloneApplication,
	setDefaultConfiguration
} from '$lib/buildPacks/common';
import yaml from 'js-yaml';
import type { ComposeFile } from '$lib/types/composeFile';

export default async function (job) {
	let {
		id: applicationId,
		repository,
		branch,
		buildPack,
		name,
		destinationDocker,
		destinationDockerId,
		gitSource,
		build_id: buildId,
		configHash,
		port,
		installCommand,
		buildCommand,
		startCommand,
		fqdn,
		baseDirectory,
		publishDirectory,
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
		pythonVariable
	} = job.data;
	const { debug } = settings;

	await asyncSleep(500);
	await db.prisma.build.updateMany({
		where: {
			status: { in: ['queued', 'running'] },
			id: { not: buildId },
			applicationId,
			createdAt: { lt: new Date(new Date().getTime() - 60 * 60 * 1000) }
		},
		data: { status: 'failed' }
	});
	let imageId = applicationId;
	let domain = getDomain(fqdn);
	let volumes =
		persistentStorage?.map((storage) => {
			return `${applicationId}${storage.path.replace(/\//gi, '-')}:${
				buildPack !== 'docker' ? '/app' : ''
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

		await db.prisma.build.update({ where: { id: buildId }, data: { status: 'running' } });
		const { workdir, repodir } = await createDirectories({ repository, buildId });

		const configuration = await setDefaultConfiguration(job.data);

		buildPack = configuration.buildPack;
		port = configuration.port;
		installCommand = configuration.installCommand;
		startCommand = configuration.startCommand;
		buildCommand = configuration.buildCommand;
		publishDirectory = configuration.publishDirectory;
		baseDirectory = configuration.baseDirectory;

		let commit = await importers[gitSource.type]({
			applicationId,
			debug,
			workdir,
			repodir,
			githubAppId: gitSource.githubApp?.id,
			gitlabAppId: gitSource.gitlabApp?.id,
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
			await db.prisma.build.update({ where: { id: buildId }, data: { commit } });
		} catch (err) {
			console.log(err);
		}

		if (!pullmergeRequestId) {
			const currentHash = crypto
				.createHash('sha256')
				.update(
					JSON.stringify({
						buildPack,
						port,
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
				await db.prisma.application.update({
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
			imageFound = false;
		} catch (error) {
			//
		}
		if (!imageFound || deployNeeded) {
			await copyBaseConfigurationFiles(buildPack, workdir, buildId, applicationId);
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
					port,
					installCommand,
					buildCommand,
					startCommand,
					baseDirectory,
					secrets,
					phpModules,
					pythonWSGI,
					pythonModule,
					pythonVariable
				});
			else {
				await saveBuildLog({ line: `Build pack ${buildPack} not found`, buildId, applicationId });
				throw new Error(`Build pack ${buildPack} not found.`);
			}
			deployNeeded = true;
		} else {
			deployNeeded = false;
			await saveBuildLog({ line: 'Nothing changed.', buildId, applicationId });
		}

		// Deploy to Docker Engine
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
			port,
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
			const composeFile: ComposeFile = {
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
						restart: 'always'
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
			sentry.captureException(error);
			throw new Error(error);
		}
		await saveBuildLog({ line: 'Proxy will be updated shortly.', buildId, applicationId });
	}
}
