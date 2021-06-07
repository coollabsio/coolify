import type { Request } from '@sveltejs/kit';
import yaml from 'js-yaml';
import { promises as fs } from 'fs';
import { docker } from '$lib/api/docker';
import { baseServiceConfiguration } from '$lib/api/applications/common';
import { cleanupTmp, execShellAsync } from '$lib/api/common';

export async function post(request: Request) {
    let { baseURL } = request.body;
    const traefikURL = baseURL;
    baseURL = `https://${baseURL}`;
    const workdir = '/tmp/nocodb';
    const deployId = 'nocodb'
    const stack = {
        version: '3.8',
        services: {
            [deployId]: {
                image: 'nocodb/nocodb',
                networks: [`${docker.network}`],
                deploy: {
                    ...baseServiceConfiguration,
                    labels: [
                        'managedBy=coolify',
                        'type=service',
                        'serviceName=nocodb',
                        'configuration=' +
                        JSON.stringify({
                            baseURL
                        }),
                        'traefik.enable=true',
                        'traefik.http.services.' + deployId + '.loadbalancer.server.port=8080',
                        'traefik.http.routers.' + deployId + '.entrypoints=websecure',
                        'traefik.http.routers.' +
                        deployId +
                        '.rule=Host(`' +
                        traefikURL +
                        '`) && PathPrefix(`/`)',
                        'traefik.http.routers.' + deployId + '.tls.certresolver=letsencrypt',
                        'traefik.http.routers.' + deployId + '.middlewares=global-compress'
                    ]
                }
            },
        },
        networks: {
            [`${docker.network}`]: {
                external: true
            }
        }
    };
    await execShellAsync(`mkdir -p ${workdir}`);
    await fs.writeFile(`${workdir}/stack.yml`, yaml.dump(stack));
    await execShellAsync('docker stack rm nocodb');
    await execShellAsync(`cat ${workdir}/stack.yml | docker stack deploy --prune -c - ${deployId}`);
    cleanupTmp(workdir);
    return {
        status: 200,
        body: { message: 'OK' }
    };
}