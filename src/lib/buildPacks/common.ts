import { base64Encode } from '$lib/crypto';
import { saveBuildLog, version } from '$lib/common';
import * as db from '$lib/database';
import templates from '$lib/components/templates';
import { promises as fs } from 'fs';
import { staticDeployments } from '$lib/components/common';

export function makeLabelForStandaloneApplication({ applicationId, domain, name, type, pullmergeRequestId = null, buildPack, repository, branch, projectId, port, commit, installCommand, buildCommand, startCommand, baseDirectory, publishDirectory }) {
    return [
        '--label coolify.managed=true',
        `--label coolify.version=${version}`,
        `--label coolify.type=standalone-application`,
        `--label coolify.configuration=${base64Encode(JSON.stringify({
            applicationId,
            domain,
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
        }))}`,
    ]
}
export async function makeLabelForStandaloneDatabase({ id, image, volume }) {
    const database = await db.prisma.database.findFirst({ where: { id } })
    delete database.destinationDockerId
    delete database.createdAt
    delete database.updatedAt
    return [
        'coolify.managed=true',
        `coolify.version=${version}`,
        `coolify.type=standalone-database`,
        `coolify.configuration=${base64Encode(JSON.stringify({
            version,
            image,
            volume,
            ...database
        }))}`,
    ]
}

export async function makeLabelForServiceDatabase({ id, image, volume }) {
    const database = await db.prisma.database.findFirst({ where: { id } })
    delete database.destinationDockerId
    delete database.createdAt
    delete database.updatedAt
    return [
        'coolify.managed=true',
        `coolify.version=${version}`,
        `coolify.type=service-database`,
        `coolify.configuration=${base64Encode(JSON.stringify({
            version,
            image,
            volume,
            ...database
        }))}`,
    ]
}


export const setDefaultConfiguration = async (data) => {
    let { buildPack, port, installCommand, startCommand, buildCommand, publishDirectory } = data
    const template = templates[buildPack]

    if (!port) {
        port = template?.port || 3000

        if (buildPack === 'static') port = 80
        else if (buildPack === 'node') port = 3000
        else if (buildPack === 'php') port = 80
    }
    if (!installCommand) installCommand = template?.installCommand || 'yarn install'
    if (!startCommand) startCommand = template?.startCommand || 'yarn start'
    if (!buildCommand) buildCommand = template?.buildCommand || null
    if (!publishDirectory) publishDirectory = template?.publishDirectory || null

    return {
        buildPack,
        port,
        installCommand,
        startCommand,
        buildCommand,
        publishDirectory
    }
}

export const buildPacks = [
    { name: 'node', fancyName: 'Node.js', hoverColor: 'hover:bg-green-700', color: 'bg-green-700' },
    { name: 'static', fancyName: 'Static', hoverColor: 'hover:bg-orange-700', color: 'bg-orange-700' },
    { name: 'docker', fancyName: 'Docker', hoverColor: 'hover:bg-sky-700', color: 'bg-sky-700' },
    { name: 'svelte', fancyName: 'Svelte', hoverColor: 'hover:bg-orange-700', color: 'bg-orange-700' },
    { name: 'nestjs', fancyName: 'NestJS', hoverColor: 'hover:bg-red-700', color: 'bg-red-700' },
    { name: 'react', fancyName: 'React', hoverColor: 'hover:bg-blue-700', color: 'bg-blue-700' },
    { name: 'nextjs', fancyName: 'NextJS', hoverColor: 'hover:bg-blue-700', color: 'bg-blue-700' },
    { name: 'gatsby', fancyName: 'Gatsby', hoverColor: 'hover:bg-blue-700', color: 'bg-blue-700' },
    { name: 'vuejs', fancyName: 'VueJS', hoverColor: 'hover:bg-green-700', color: 'bg-green-700' },
    { name: 'nuxtjs', fancyName: 'NuxtJS', hoverColor: 'hover:bg-green-700', color: 'bg-green-700' },
    { name: 'php', fancyName: 'PHP', hoverColor: 'hover:bg-indigo-700', color: 'bg-indigo-700' },
    { name: 'rust', fancyName: 'Rust', hoverColor: 'hover:bg-pink-700', color: 'bg-pink-700' },
];

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
            saveBuildLog({ line: 'Copied default configuration file for PHP.', buildId, applicationId })
        } else if (staticDeployments.includes(buildPack)) {
            await fs.writeFile(
                `${workdir}/nginx.conf`,
                `user  nginx;
            worker_processes  auto;
            
            error_log  /var/log/nginx/error.log warn;
            pid        /var/run/nginx.pid;
            
            events {
                worker_connections  1024;
            }
            
            http {
                include       /etc/nginx/mime.types;
            
                access_log      off;
                sendfile        on;
                #tcp_nopush     on;
                keepalive_timeout  65;
    
                server {
                    listen       80;
                    server_name  localhost;
                    
                    location / {
                        root   /usr/share/nginx/html;
                        index  index.html;
                        try_files $uri $uri/index.html $uri/ /index.html =404;
                    }
            
                    error_page  404              /50x.html;
            
                    # redirect server error pages to the static page /50x.html
                    #
                    error_page   500 502 503 504  /50x.html;
                    location = /50x.html {
                        root   /usr/share/nginx/html;
                    }  
            
                }
            
            }
            `
            );
            saveBuildLog({ line: 'Copied default configuration file.', buildId, applicationId })
        }
    } catch (error) {
        console.log(error);
        throw new Error(error);
    }

}