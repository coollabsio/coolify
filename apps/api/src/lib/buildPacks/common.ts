import {
	base64Encode,
	decrypt,
	encrypt,
	executeCommand,
	generateSecrets,
	generateTimestamp,
	getDomain,
	isARM,
	isDev,
	prisma,
	version
} from '../common';
import { promises as fs } from 'fs';
import { day } from '../dayjs';

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

export const setDefaultConfiguration = async (data: any) => {
	let {
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
	} = data;
	//@ts-ignore
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
		if (baseDirectory.endsWith('/') && baseDirectory !== '/')
			baseDirectory = baseDirectory.slice(0, -1);
	}
	if (dockerFileLocation) {
		if (!dockerFileLocation.startsWith('/')) dockerFileLocation = `/${dockerFileLocation}`;
		if (dockerFileLocation.endsWith('/')) dockerFileLocation = dockerFileLocation.slice(0, -1);
	} else {
		dockerFileLocation = '/Dockerfile';
	}
	if (dockerComposeFileLocation) {
		if (!dockerComposeFileLocation.startsWith('/'))
			dockerComposeFileLocation = `/${dockerComposeFileLocation}`;
		if (dockerComposeFileLocation.endsWith('/'))
			dockerComposeFileLocation = dockerComposeFileLocation.slice(0, -1);
	} else {
		dockerComposeFileLocation = '/Dockerfile';
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
		dockerComposeFileLocation,
		denoMainFile
	};
};

export const scanningTemplates = {
	'@sveltejs/kit': {
		buildPack: 'nodejs'
	},
	astro: {
		buildPack: 'astro'
	},
	'@11ty/eleventy': {
		buildPack: 'eleventy'
	},
	svelte: {
		buildPack: 'svelte'
	},
	'@nestjs/core': {
		buildPack: 'nestjs'
	},
	next: {
		buildPack: 'nextjs'
	},
	nuxt: {
		buildPack: 'nuxtjs'
	},
	'react-scripts': {
		buildPack: 'react'
	},
	'parcel-bundler': {
		buildPack: 'static'
	},
	'@vue/cli-service': {
		buildPack: 'vuejs'
	},
	vuejs: {
		buildPack: 'vuejs'
	},
	gatsby: {
		buildPack: 'gatsby'
	},
	'preact-cli': {
		buildPack: 'react'
	}
};

export const saveBuildLog = async ({
	line,
	buildId,
	applicationId
}: {
	line: string;
	buildId: string;
	applicationId: string;
}): Promise<any> => {
	if (buildId === 'undefined' || buildId === 'null' || !buildId) return;
	if (applicationId === 'undefined' || applicationId === 'null' || !applicationId) return;
	const { default: got } = await import('got');
	if (typeof line === 'object' && line) {
		if (line.shortMessage) {
			line = line.shortMessage + '\n' + line.stderr;
		} else {
			line = JSON.stringify(line);
		}
	}
	if (line && typeof line === 'string' && line.includes('ghs_')) {
		const regex = /ghs_.*@/g;
		line = line.replace(regex, '<SENSITIVE_DATA_DELETED>@');
	}
	const addTimestamp = `[${generateTimestamp()}] ${line}`;
	const fluentBitUrl = isDev
		? process.env.COOLIFY_CONTAINER_DEV === 'true'
			? 'http://coolify-fluentbit:24224'
			: 'http://localhost:24224'
		: 'http://coolify-fluentbit:24224';

	if (isDev && !process.env.COOLIFY_CONTAINER_DEV) {
		console.debug(`[${applicationId}] ${addTimestamp}`);
	}
	try {
		return await got.post(`${fluentBitUrl}/${applicationId}_buildlog_${buildId}.csv`, {
			json: {
				line: encrypt(line)
			}
		});
	} catch (error) {
		return await prisma.buildLog.create({
			data: {
				line: addTimestamp,
				buildId,
				time: Number(day().valueOf()),
				applicationId
			}
		});
	}
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
		} else if (baseImage?.includes('nginx')) {
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
		// TODO: Add more configuration files for other buildpacks, like apache2, etc.
	} catch (error) {
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

export async function saveDockerRegistryCredentials({ url, username, password, workdir }) {
	if (!username || !password) {
		return null;
	}

	let decryptedPassword = decrypt(password);
	const location = `${workdir}/.docker`;

	try {
		await fs.mkdir(`${workdir}/.docker`);
	} catch (error) {
		// console.log(error);
	}
	const payload = JSON.stringify({
		auths: {
			[url]: {
				auth: Buffer.from(`${username}:${decryptedPassword}`).toString('base64')
			}
		}
	});
	await fs.writeFile(`${location}/config.json`, payload);
	return location;
}
export async function buildImage({
	applicationId,
	tag,
	workdir,
	buildId,
	dockerId,
	isCache = false,
	debug = false,
	dockerFileLocation = '/Dockerfile',
	commit,
	forceRebuild = false
}) {
	if (isCache) {
		await saveBuildLog({ line: `Building cache image...`, buildId, applicationId });
	} else {
		await saveBuildLog({ line: `Building production image...`, buildId, applicationId });
	}
	const dockerFile = isCache ? `${dockerFileLocation}-cache` : `${dockerFileLocation}`;
	const cache = `${applicationId}:${tag}${isCache ? '-cache' : ''}`;
	let location = null;

	const { dockerRegistry } = await prisma.application.findUnique({
		where: { id: applicationId },
		select: { dockerRegistry: true }
	});
	if (dockerRegistry) {
		const { url, username, password } = dockerRegistry;
		location = await saveDockerRegistryCredentials({ url, username, password, workdir });
	}

	await executeCommand({
		stream: true,
		debug,
		buildId,
		applicationId,
		dockerId,
		command: `docker ${location ? `--config ${location}` : ''} build ${
			forceRebuild ? '--no-cache' : ''
		} --progress plain -f ${workdir}/${dockerFile} -t ${cache} --build-arg SOURCE_COMMIT=${commit} ${workdir}`
	});

	const { status } = await prisma.build.findUnique({ where: { id: buildId } });
	if (status === 'canceled') {
		throw new Error('Canceled.');
	}
}
export function makeLabelForSimpleDockerfile({ applicationId, port, type }) {
	return [
		'coolify.managed=true',
		`coolify.version=${version}`,
		`coolify.applicationId=${applicationId}`,
		`coolify.type=standalone-application`
	];
}
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
		`coolify.applicationId=${applicationId}`,
		`coolify.type=standalone-application`,
		`coolify.name=${name}`,
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

export async function buildCacheImageWithNode(data, imageForBuild) {
	const {
		workdir,
		buildId,
		baseDirectory,
		installCommand,
		buildCommand,
		secrets,
		pullmergeRequestId
	} = data;
	const isPnpm = checkPnpm(installCommand, buildCommand);
	const Dockerfile: Array<string> = [];
	Dockerfile.push(`FROM ${imageForBuild}`);
	Dockerfile.push('WORKDIR /app');
	Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
	if (secrets.length > 0) {
		generateSecrets(secrets, pullmergeRequestId, true).forEach((env) => {
			Dockerfile.push(env);
		});
	}
	if (isPnpm) {
		Dockerfile.push('RUN curl -f https://get.pnpm.io/v6.16.js | node - add --global pnpm@7');
	}
	Dockerfile.push(`COPY .${baseDirectory || ''} ./`);
	if (installCommand) {
		Dockerfile.push(`RUN ${installCommand}`);
	}
	Dockerfile.push(`RUN ${buildCommand}`);
	await fs.writeFile(`${workdir}/Dockerfile-cache`, Dockerfile.join('\n'));
	await buildImage({ ...data, isCache: true });
}

export async function buildCacheImageForLaravel(data, imageForBuild) {
	const { workdir, buildId, secrets, pullmergeRequestId } = data;
	const Dockerfile: Array<string> = [];
	Dockerfile.push(`FROM ${imageForBuild}`);
	Dockerfile.push('WORKDIR /app');
	Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
	if (secrets.length > 0) {
		generateSecrets(secrets, pullmergeRequestId, true).forEach((env) => {
			Dockerfile.push(env);
		});
	}
	Dockerfile.push(`COPY *.json *.mix.js /app/`);
	Dockerfile.push(`COPY resources /app/resources`);
	Dockerfile.push(`RUN yarn install && yarn production`);
	await fs.writeFile(`${workdir}/Dockerfile-cache`, Dockerfile.join('\n'));
	await buildImage({ ...data, isCache: true });
}

export async function buildCacheImageWithCargo(data, imageForBuild) {
	const { applicationId, workdir, buildId } = data;

	const Dockerfile: Array<string> = [];
	Dockerfile.push(`FROM ${imageForBuild} as planner-${applicationId}`);
	Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
	Dockerfile.push('WORKDIR /app');
	Dockerfile.push('RUN cargo install cargo-chef');
	Dockerfile.push('COPY . .');
	Dockerfile.push('RUN cargo chef prepare --recipe-path recipe.json');
	Dockerfile.push(`FROM ${imageForBuild}`);
	Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
	Dockerfile.push('WORKDIR /app');
	Dockerfile.push('RUN cargo install cargo-chef');
	Dockerfile.push(`COPY --from=planner-${applicationId} /app/recipe.json recipe.json`);
	Dockerfile.push('RUN cargo chef cook --release --recipe-path recipe.json');
	await fs.writeFile(`${workdir}/Dockerfile-cache`, Dockerfile.join('\n'));
	await buildImage({ ...data, isCache: true });
}
