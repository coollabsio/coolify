import { base64Encode } from '$lib/crypto';
import { getDomain, saveBuildLog, version } from '$lib/common';
import * as db from '$lib/database';
import { scanningTemplates } from '$lib/components/templates';
import { promises as fs } from 'fs';
import { staticDeployments } from '$lib/components/common';

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
		'--label coolify.managed=true',
		`--label coolify.version=${version}`,
		`--label coolify.type=standalone-application`,
		`--label coolify.configuration=${base64Encode(
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
		baseDirectory
	} = data;
	const template = scanningTemplates[buildPack];
	if (!port) {
		port = template?.port || 3000;

		if (buildPack === 'static') port = 80;
		else if (buildPack === 'node') port = 3000;
		else if (buildPack === 'php') port = 80;
	}
	if (!installCommand) installCommand = template?.installCommand || 'yarn install';
	if (!startCommand) startCommand = template?.startCommand || 'yarn start';
	if (!buildCommand) buildCommand = template?.buildCommand || null;
	if (!publishDirectory) publishDirectory = template?.publishDirectory || null;
	if (baseDirectory) {
		if (!baseDirectory.startsWith('/')) baseDirectory = `/${baseDirectory}`;
		if (!baseDirectory.endsWith('/')) baseDirectory = `${baseDirectory}/`;
	}

	return {
		buildPack,
		port,
		installCommand,
		startCommand,
		buildCommand,
		publishDirectory,
		baseDirectory
	};
};

export async function copyBaseConfigurationFiles(buildPack, workdir, buildId, applicationId) {
	try {
		// TODO: Write full .dockerignore for all deployments!!
		if (buildPack === 'php') {
			await fs.writeFile(
				`${workdir}/.htaccess`,
				`
        RewriteEngine On
        RewriteBase /
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteRule ^(.+)$ index.php [QSA,L]
        `
			);
			await fs.writeFile(`${workdir}/entrypoint.sh`, `chown -R 1000 /app`);
			saveBuildLog({ line: 'Copied default configuration file for PHP.', buildId, applicationId });
		} else if (staticDeployments.includes(buildPack)) {
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
			saveBuildLog({ line: 'Copied default configuration file.', buildId, applicationId });
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
