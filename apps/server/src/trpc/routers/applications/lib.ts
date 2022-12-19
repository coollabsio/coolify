import cuid from 'cuid';
import crypto from 'node:crypto';
import { decrypt, isARM } from '../../../lib/common';

import { prisma } from '../../../prisma';

export async function deployApplication(
	id: string,
	teamId: string,
	forceRebuild: boolean = false
): Promise<string | Error> {
	const buildId = cuid();
	const application = await getApplicationFromDB(id, teamId);
	if (application) {
		if (!application?.configHash) {
			await generateConfigHash(
				id,
				application.buildPack,
				application.port,
				application.exposePort,
				application.installCommand,
				application.buildCommand,
				application.startCommand
			);
		}
		await prisma.application.update({ where: { id }, data: { updatedAt: new Date() } });
		if (application.gitSourceId) {
			await prisma.build.create({
				data: {
					id: buildId,
					applicationId: id,
					branch: application.branch,
					forceRebuild,
					destinationDockerId: application.destinationDocker?.id,
					gitSourceId: application.gitSource?.id,
					githubAppId: application.gitSource?.githubApp?.id,
					gitlabAppId: application.gitSource?.gitlabApp?.id,
					status: 'queued',
					type: 'manual'
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
		return buildId;
	}
	throw { status: 500, message: 'Application cannot be deployed.' };
}
export async function generateConfigHash(
	id: string,
	buildPack: string,
	port: number,
	exposePort: number,
	installCommand: string,
	buildCommand: string,
	startCommand: string
): Promise<any> {
	const configHash = crypto
		.createHash('sha256')
		.update(
			JSON.stringify({
				buildPack,
				port,
				exposePort,
				installCommand,
				buildCommand,
				startCommand
			})
		)
		.digest('hex');
	return await prisma.application.update({ where: { id }, data: { configHash } });
}
export async function getApplicationFromDB(id: string, teamId: string) {
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
	const { baseImage, baseBuildImage, baseBuildImages, baseImages } = setDefaultBaseImage(buildPack);

	// Set default build images
	if (application && !application.baseImage) {
		application.baseImage = baseImage;
	}
	if (application && !application.baseBuildImage) {
		application.baseBuildImage = baseBuildImage;
	}
	return { ...application, baseBuildImages, baseImages };
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

const staticApps = ['static', 'react', 'vuejs', 'svelte', 'gatsby', 'astro', 'eleventy'];
const nodeBased = [
	'react',
	'preact',
	'vuejs',
	'svelte',
	'gatsby',
	'astro',
	'eleventy',
	'node',
	'nestjs',
	'nuxtjs',
	'nextjs'
];
export function setDefaultBaseImage(
	buildPack: string | null,
	deploymentType: string | null = null
) {
	const nodeVersions = [
		{
			value: 'node:lts',
			label: 'node:lts'
		},
		{
			value: 'node:18',
			label: 'node:18'
		},
		{
			value: 'node:17',
			label: 'node:17'
		},
		{
			value: 'node:16',
			label: 'node:16'
		},
		{
			value: 'node:14',
			label: 'node:14'
		},
		{
			value: 'node:12',
			label: 'node:12'
		}
	];
	const staticVersions = [
		{
			value: 'webdevops/nginx:alpine',
			label: 'webdevops/nginx:alpine'
		},
		{
			value: 'webdevops/apache:alpine',
			label: 'webdevops/apache:alpine'
		},
		{
			value: 'nginx:alpine',
			label: 'nginx:alpine'
		},
		{
			value: 'httpd:alpine',
			label: 'httpd:alpine (Apache)'
		}
	];
	const rustVersions = [
		{
			value: 'rust:latest',
			label: 'rust:latest'
		},
		{
			value: 'rust:1.60',
			label: 'rust:1.60'
		},
		{
			value: 'rust:1.60-buster',
			label: 'rust:1.60-buster'
		},
		{
			value: 'rust:1.60-bullseye',
			label: 'rust:1.60-bullseye'
		},
		{
			value: 'rust:1.60-slim-buster',
			label: 'rust:1.60-slim-buster'
		},
		{
			value: 'rust:1.60-slim-bullseye',
			label: 'rust:1.60-slim-bullseye'
		},
		{
			value: 'rust:1.60-alpine3.14',
			label: 'rust:1.60-alpine3.14'
		},
		{
			value: 'rust:1.60-alpine3.15',
			label: 'rust:1.60-alpine3.15'
		}
	];
	const phpVersions = [
		{
			value: 'webdevops/php-apache:8.2',
			label: 'webdevops/php-apache:8.2'
		},
		{
			value: 'webdevops/php-nginx:8.2',
			label: 'webdevops/php-nginx:8.2'
		},
		{
			value: 'webdevops/php-apache:8.1',
			label: 'webdevops/php-apache:8.1'
		},
		{
			value: 'webdevops/php-nginx:8.1',
			label: 'webdevops/php-nginx:8.1'
		},
		{
			value: 'webdevops/php-apache:8.0',
			label: 'webdevops/php-apache:8.0'
		},
		{
			value: 'webdevops/php-nginx:8.0',
			label: 'webdevops/php-nginx:8.0'
		},
		{
			value: 'webdevops/php-apache:7.4',
			label: 'webdevops/php-apache:7.4'
		},
		{
			value: 'webdevops/php-nginx:7.4',
			label: 'webdevops/php-nginx:7.4'
		},
		{
			value: 'webdevops/php-apache:7.3',
			label: 'webdevops/php-apache:7.3'
		},
		{
			value: 'webdevops/php-nginx:7.3',
			label: 'webdevops/php-nginx:7.3'
		},
		{
			value: 'webdevops/php-apache:7.2',
			label: 'webdevops/php-apache:7.2'
		},
		{
			value: 'webdevops/php-nginx:7.2',
			label: 'webdevops/php-nginx:7.2'
		},
		{
			value: 'webdevops/php-apache:7.1',
			label: 'webdevops/php-apache:7.1'
		},
		{
			value: 'webdevops/php-nginx:7.1',
			label: 'webdevops/php-nginx:7.1'
		},
		{
			value: 'webdevops/php-apache:7.0',
			label: 'webdevops/php-apache:7.0'
		},
		{
			value: 'webdevops/php-nginx:7.0',
			label: 'webdevops/php-nginx:7.0'
		},
		{
			value: 'webdevops/php-apache:5.6',
			label: 'webdevops/php-apache:5.6'
		},
		{
			value: 'webdevops/php-nginx:5.6',
			label: 'webdevops/php-nginx:5.6'
		},
		{
			value: 'webdevops/php-apache:8.2-alpine',
			label: 'webdevops/php-apache:8.2-alpine'
		},
		{
			value: 'webdevops/php-nginx:8.2-alpine',
			label: 'webdevops/php-nginx:8.2-alpine'
		},
		{
			value: 'webdevops/php-apache:8.1-alpine',
			label: 'webdevops/php-apache:8.1-alpine'
		},
		{
			value: 'webdevops/php-nginx:8.1-alpine',
			label: 'webdevops/php-nginx:8.1-alpine'
		},
		{
			value: 'webdevops/php-apache:8.0-alpine',
			label: 'webdevops/php-apache:8.0-alpine'
		},
		{
			value: 'webdevops/php-nginx:8.0-alpine',
			label: 'webdevops/php-nginx:8.0-alpine'
		},
		{
			value: 'webdevops/php-apache:7.4-alpine',
			label: 'webdevops/php-apache:7.4-alpine'
		},
		{
			value: 'webdevops/php-nginx:7.4-alpine',
			label: 'webdevops/php-nginx:7.4-alpine'
		},
		{
			value: 'webdevops/php-apache:7.3-alpine',
			label: 'webdevops/php-apache:7.3-alpine'
		},
		{
			value: 'webdevops/php-nginx:7.3-alpine',
			label: 'webdevops/php-nginx:7.3-alpine'
		},
		{
			value: 'webdevops/php-apache:7.2-alpine',
			label: 'webdevops/php-apache:7.2-alpine'
		},
		{
			value: 'webdevops/php-nginx:7.2-alpine',
			label: 'webdevops/php-nginx:7.2-alpine'
		},
		{
			value: 'webdevops/php-apache:7.1-alpine',
			label: 'webdevops/php-apache:7.1-alpine'
		},
		{
			value: 'php:8.1-fpm',
			label: 'php:8.1-fpm'
		},
		{
			value: 'php:8.0-fpm',
			label: 'php:8.0-fpm'
		},
		{
			value: 'php:8.1-fpm-alpine',
			label: 'php:8.1-fpm-alpine'
		},
		{
			value: 'php:8.0-fpm-alpine',
			label: 'php:8.0-fpm-alpine'
		}
	];
	const pythonVersions = [
		{
			value: 'python:3.10-alpine',
			label: 'python:3.10-alpine'
		},
		{
			value: 'python:3.10-buster',
			label: 'python:3.10-buster'
		},
		{
			value: 'python:3.10-bullseye',
			label: 'python:3.10-bullseye'
		},
		{
			value: 'python:3.10-slim-bullseye',
			label: 'python:3.10-slim-bullseye'
		},
		{
			value: 'python:3.9-alpine',
			label: 'python:3.9-alpine'
		},
		{
			value: 'python:3.9-buster',
			label: 'python:3.9-buster'
		},
		{
			value: 'python:3.9-bullseye',
			label: 'python:3.9-bullseye'
		},
		{
			value: 'python:3.9-slim-bullseye',
			label: 'python:3.9-slim-bullseye'
		},
		{
			value: 'python:3.8-alpine',
			label: 'python:3.8-alpine'
		},
		{
			value: 'python:3.8-buster',
			label: 'python:3.8-buster'
		},
		{
			value: 'python:3.8-bullseye',
			label: 'python:3.8-bullseye'
		},
		{
			value: 'python:3.8-slim-bullseye',
			label: 'python:3.8-slim-bullseye'
		},
		{
			value: 'python:3.7-alpine',
			label: 'python:3.7-alpine'
		},
		{
			value: 'python:3.7-buster',
			label: 'python:3.7-buster'
		},
		{
			value: 'python:3.7-bullseye',
			label: 'python:3.7-bullseye'
		},
		{
			value: 'python:3.7-slim-bullseye',
			label: 'python:3.7-slim-bullseye'
		}
	];
	const herokuVersions = [
		{
			value: 'heroku/builder:22',
			label: 'heroku/builder:22'
		},
		{
			value: 'heroku/buildpacks:20',
			label: 'heroku/buildpacks:20'
		},
		{
			value: 'heroku/builder-classic:22',
			label: 'heroku/builder-classic:22'
		}
	];
	let payload: any = {
		baseImage: null,
		baseBuildImage: null,
		baseImages: [],
		baseBuildImages: []
	};
	if (nodeBased.includes(buildPack)) {
		if (deploymentType === 'static') {
			payload.baseImage = isARM(process.arch) ? 'nginx:alpine' : 'webdevops/nginx:alpine';
			payload.baseImages = isARM(process.arch)
				? staticVersions.filter((version) => !version.value.includes('webdevops'))
				: staticVersions;
			payload.baseBuildImage = 'node:lts';
			payload.baseBuildImages = nodeVersions;
		} else {
			payload.baseImage = 'node:lts';
			payload.baseImages = nodeVersions;
			payload.baseBuildImage = 'node:lts';
			payload.baseBuildImages = nodeVersions;
		}
	}
	if (staticApps.includes(buildPack)) {
		payload.baseImage = isARM(process.arch) ? 'nginx:alpine' : 'webdevops/nginx:alpine';
		payload.baseImages = isARM(process.arch)
			? staticVersions.filter((version) => !version.value.includes('webdevops'))
			: staticVersions;
		payload.baseBuildImage = 'node:lts';
		payload.baseBuildImages = nodeVersions;
	}
	if (buildPack === 'python') {
		payload.baseImage = 'python:3.10-alpine';
		payload.baseImages = pythonVersions;
	}
	if (buildPack === 'rust') {
		payload.baseImage = 'rust:latest';
		payload.baseBuildImage = 'rust:latest';
		payload.baseImages = rustVersions;
		payload.baseBuildImages = rustVersions;
	}
	if (buildPack === 'deno') {
		payload.baseImage = 'denoland/deno:latest';
	}
	if (buildPack === 'php') {
		payload.baseImage = isARM(process.arch)
			? 'php:8.1-fpm-alpine'
			: 'webdevops/php-apache:8.2-alpine';
		payload.baseImages = isARM(process.arch)
			? phpVersions.filter((version) => !version.value.includes('webdevops'))
			: phpVersions;
	}
	if (buildPack === 'laravel') {
		payload.baseImage = isARM(process.arch)
			? 'php:8.1-fpm-alpine'
			: 'webdevops/php-apache:8.2-alpine';
		payload.baseImages = isARM(process.arch)
			? phpVersions.filter((version) => !version.value.includes('webdevops'))
			: phpVersions;
		payload.baseBuildImage = 'node:18';
		payload.baseBuildImages = nodeVersions;
	}
	if (buildPack === 'heroku') {
		payload.baseImage = 'heroku/buildpacks:20';
		payload.baseImages = herokuVersions;
	}
	return payload;
}
