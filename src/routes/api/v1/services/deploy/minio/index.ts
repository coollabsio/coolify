import type { Request } from '@sveltejs/kit';
import yaml from 'js-yaml';
import generator from 'generate-password';
import { promises as fs } from 'fs';
import { docker } from '$lib/api/docker';
import { baseServiceConfiguration } from '$lib/api/applications/common';
import { cleanupTmp, execShellAsync } from '$lib/api/common';

export async function post(request: Request) {
	let { baseURL } = request.body;
	const traefikURL = baseURL;
	baseURL = `https://${baseURL}`;
	const workdir = '/tmp/minio';
	const deployId = 'minio';
	const secrets = [
		{ name: 'MINIO_ROOT_USER', value: generator.generate({ length: 12, numbers: true, strict: true }) },
		{ name: 'MINIO_ROOT_PASSWORD', value: generator.generate({ length: 24, numbers: true, strict: true }) }

	];
	const generateEnvsMinIO = {};
	for (const secret of secrets) generateEnvsMinIO[secret.name] = secret.value;

	const stack = {
		version: '3.8',
		services: {
			[deployId]: {
				image: 'minio/minio',
				command: 'server /data',
				networks: [`${docker.network}`],
				environment: generateEnvsMinIO,
				volumes: [`${deployId}-minio-data:/data`],
				deploy: {
					...baseServiceConfiguration,
					labels: [
						'managedBy=coolify',
						'type=service',
						'serviceName=minio',
						'configuration=' +
						JSON.stringify({
							baseURL,
							generateEnvsMinIO
						}),
						'traefik.enable=true',
						'traefik.http.services.' + deployId + '.loadbalancer.server.port=9000',
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
			[`${deployId}-minio-data`]: {
				external: true
			},
		},
	};
	await execShellAsync(`mkdir -p ${workdir}`);
	await fs.writeFile(`${workdir}/stack.yml`, yaml.dump(stack));
	await execShellAsync('docker stack rm minio');
	await execShellAsync(`cat ${workdir}/stack.yml | docker stack deploy --prune -c - ${deployId}`);
	cleanupTmp(workdir);
	return {
		status: 200,
		body: { message: 'OK' }
	};
}
