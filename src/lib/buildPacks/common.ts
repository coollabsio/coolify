import { base64Encode } from '$lib/crypto';
import { getDomain, saveBuildLog, version } from '$lib/common';
import * as db from '$lib/database';
import { scanningTemplates } from '$lib/components/templates';
import { promises as fs } from 'fs';
import { staticDeployments } from '$lib/components/common';

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

export function makeLabelForStandaloneApplication({
	applicationId,
	fqdn,
	name,
	type,
	pullmergeRequestId = null,
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
}) {
	if (pullmergeRequestId) {
		const protocol = fqdn.startsWith('https://') ? 'https' : 'http';
		const domain = getDomain(fqdn);
		fqdn = `${protocol}://${pullmergeRequestId}.${domain}`;
	}
	return [
		'coolify.managed=true',
		`coolify.version=${version}`,
		`coolify.type=standalone-application`,
		`coolify.configuration=${base64Encode(
			JSON.stringify({
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
			})
		)}`
	];
}
export async function makeLabelForStandaloneDatabase({ id, image, volume }) {
	const database = await db.prisma.database.findFirst({ where: { id } });
	delete database.destinationDockerId;
	delete database.createdAt;
	delete database.updatedAt;
	return [
		'coolify.managed=true',
		`coolify.version=${version}`,
		`coolify.type=standalone-database`,
		`coolify.configuration=${base64Encode(
			JSON.stringify({
				version,
				image,
				volume,
				...database
			})
		)}`
	];
}

export function makeLabelForServices(type) {
	return [
		'coolify.managed=true',
		`coolify.version=${version}`,
		`coolify.type=service`,
		`coolify.service.type=${type}`
	];
}

export const setDefaultConfiguration = async (data) => {
	let {
		buildPack,
		port,
		installCommand,
		startCommand,
		buildCommand,
		publishDirectory,
		baseDirectory,
		dockerFileLocation,
		denoMainFile
	} = data;
	const template = scanningTemplates[buildPack];
	if (!port) {
		port = template?.port || 3000;

		if (buildPack === 'static') port = 80;
		else if (buildPack === 'node') port = 3000;
		else if (buildPack === 'php') port = 80;
		else if (buildPack === 'python') port = 8000;
	}
	if (!installCommand && buildPack !== 'static' && buildPack !== 'laravel')
		installCommand = template?.installCommand || 'yarn install';
	if (!startCommand && buildPack !== 'static' && buildPack !== 'laravel')
		startCommand = template?.startCommand || 'yarn start';
	if (!buildCommand && buildPack !== 'static' && buildPack !== 'laravel')
		buildCommand = template?.buildCommand || null;
	if (!publishDirectory) publishDirectory = template?.publishDirectory || null;
	if (baseDirectory) {
		if (!baseDirectory.startsWith('/')) baseDirectory = `/${baseDirectory}`;
		if (!baseDirectory.endsWith('/')) baseDirectory = `${baseDirectory}/`;
	}
	if (dockerFileLocation) {
		if (!dockerFileLocation.startsWith('/')) dockerFileLocation = `/${dockerFileLocation}`;
		if (dockerFileLocation.endsWith('/')) dockerFileLocation = dockerFileLocation.slice(0, -1);
	} else {
		dockerFileLocation = '/Dockerfile';
	}
	if (!denoMainFile) {
		denoMainFile = 'main.ts';
	}

	return {
		buildPack,
		port,
		installCommand,
		startCommand,
		buildCommand,
		publishDirectory,
		baseDirectory,
		dockerFileLocation,
		denoMainFile
	};
};

export async function copyBaseConfigurationFiles(
	buildPack,
	workdir,
	buildId,
	applicationId,
	baseImage
) {
	try {
		if (buildPack === 'php') {
			await fs.writeFile(`${workdir}/entrypoint.sh`, `chown -R 1000 /app`);
			await saveBuildLog({
				line: 'Copied default configuration file for PHP.',
				buildId,
				applicationId
			});
		} else if (staticDeployments.includes(buildPack) && baseImage.includes('nginx')) {
			await fs.writeFile(
				`${workdir}/nginx.conf`,
				`user  nginx;
            worker_processes  auto;
            
            error_log  /docker.stdout;
            pid        /run/nginx.pid;
            
            events {
                worker_connections  1024;
            }
            
            http {
				log_format  main  '$remote_addr - $remote_user [$time_local] "$request" '
				'$status $body_bytes_sent "$http_referer" '
				'"$http_user_agent" "$http_x_forwarded_for"';

                access_log  /docker.stdout main;

				sendfile            on;
				tcp_nopush          on;
				tcp_nodelay         on;
				keepalive_timeout   65;
				types_hash_max_size 2048;

			    include             /etc/nginx/mime.types;
    			default_type        application/octet-stream;
				
                server {
                    listen       80;
                    server_name  localhost;
                    
                    location / {
                        root   /app;
                        index  index.html;
                        try_files $uri $uri/index.html $uri/ /index.html =404;
                    }
            
                    error_page  404              /50x.html;
            
                    # redirect server error pages to the static page /50x.html
                    #
                    error_page   500 502 503 504  /50x.html;
                    location = /50x.html {
                        root   /app;
                    }  
            
                }
            
            }
            `
			);
		}
	} catch (error) {
		console.log(error);
		throw new Error(error);
	}
}

export function checkPnpm(installCommand = null, buildCommand = null, startCommand = null) {
	return (
		installCommand?.includes('pnpm') ||
		buildCommand?.includes('pnpm') ||
		startCommand?.includes('pnpm')
	);
}

export function setDefaultBaseImage(buildPack) {
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
			value: 'webdevops/php-nginx:7.1-alpine',
			label: 'webdevops/php-nginx:7.1-alpine'
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
	let payload = {
		baseImage: null,
		baseBuildImage: null,
		baseImages: [],
		baseBuildImages: []
	};
	if (nodeBased.includes(buildPack)) {
		payload.baseImage = 'node:lts';
		payload.baseImages = nodeVersions;
		payload.baseBuildImage = 'node:lts';
		payload.baseBuildImages = nodeVersions;
	}
	if (staticApps.includes(buildPack)) {
		payload.baseImage = 'webdevops/nginx:alpine';
		payload.baseImages = staticVersions;
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
		payload.baseImage = 'webdevops/php-apache:8.0-alpine';
		payload.baseImages = phpVersions;
	}
	if (buildPack === 'laravel') {
		payload.baseImage = 'webdevops/php-apache:8.0-alpine';
		payload.baseBuildImage = 'node:18';
		payload.baseBuildImages = nodeVersions;
	}
	return payload;
}
