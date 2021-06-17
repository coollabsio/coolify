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
	const workdir = '/tmp/code-server';
	const deployId = 'code-server';
	// const environment = [
	// 	{ name: 'DOCKER_USER', value: 'root' }

	// ];
	// const generateEnvsCodeServer = {};
	// for (const env of environment) generateEnvsCodeServer[env.name] = env.value;

	const stack = {
		version: '3.8',
		services: {
			[deployId]: {
				image: 'codercom/code-server',
				command: 'code-server --disable-telemetry',
				networks: [`${docker.network}`],
				volumes: [`${deployId}-code-server-data:/home/coder`],
				// environment: generateEnvsCodeServer,
				deploy: {
					...baseServiceConfiguration,
					labels: [
						'managedBy=coolify',
						'type=service',
						'serviceName=code-server',
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
			}
		},
		networks: {
			[`${docker.network}`]: {
				external: true
			}
		},
		volumes: {
			[`${deployId}-code-server-data`]: {
				external: true
			},
		},
	};
	await execShellAsync(`mkdir -p ${workdir}`);
	await fs.writeFile(`${workdir}/stack.yml`, yaml.dump(stack));
	await execShellAsync('docker stack rm code-server');
	await execShellAsync(`cat ${workdir}/stack.yml | docker stack deploy --prune -c - ${deployId}`);
	cleanupTmp(workdir);
	return {
		status: 200,
		body: { message: 'OK' }
	};
}
