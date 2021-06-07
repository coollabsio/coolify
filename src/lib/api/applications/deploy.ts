import { docker } from '$lib/api/docker';
import { saveAppLog } from './logging';
import { promises as fs } from 'fs';
import { deleteSameDeployments, purgeImagesContainers } from './cleanup';
import yaml from 'js-yaml';
import { delay, execShellAsync } from '../common';

export default async function (configuration, imageChanged) {
	const generateEnvs = {};
	for (const secret of configuration.publish.secrets) {
		generateEnvs[secret.name] = secret.value;
	}
	const containerName = configuration.build.container.name;
	const containerTag = configuration.build.container.tag;

	// Only save SHA256 of it in the configuration label
	const baseServiceConfiguration = configuration.baseServiceConfiguration;
	delete configuration.baseServiceConfiguration;

	const stack = {
		version: '3.8',
		services: {
			[containerName]: {
				image: `${containerName}:${containerTag}`,
				networks: [`${docker.network}`],
				environment: generateEnvs,
				deploy: {
					...baseServiceConfiguration,
					labels: [
						'managedBy=coolify',
						'type=application',
						'configuration=' + JSON.stringify(configuration),
						'traefik.enable=true',
						'traefik.http.services.' +
							containerName +
							`.loadbalancer.server.port=${configuration.publish.port}`,
						'traefik.http.routers.' + containerName + '.entrypoints=websecure',
						'traefik.http.routers.' +
							containerName +
							'.rule=Host(`' +
							configuration.publish.domain +
							'`) && PathPrefix(`' +
							configuration.publish.path +
							'`)',
						'traefik.http.routers.' + containerName + '.tls.certresolver=letsencrypt',
						'traefik.http.routers.' + containerName + '.middlewares=global-compress'
					]
				}
			}
		},
		networks: {
			[`${docker.network}`]: {
				external: true
			}
		}
	};
	await saveAppLog('### Publishing.', configuration);
	await fs.writeFile(`${configuration.general.workdir}/stack.yml`, yaml.dump(stack));
	if (imageChanged) {
		// console.log('image changed')
		await execShellAsync(
			`docker service update --image ${containerName}:${containerTag} ${containerName}_${containerName}`
		);
	} else {
		// console.log('new deployment or force deployment or config changed')
		await deleteSameDeployments(configuration);
		await execShellAsync(
			`cat ${configuration.general.workdir}/stack.yml | docker stack deploy --prune -c - ${containerName}`
		);
	}
	async function purgeImagesAsync(found) {
		await delay(10000);
		await purgeImagesContainers(found, true);
	}
	purgeImagesAsync(configuration);

	await saveAppLog('### Published done!', configuration);
}
