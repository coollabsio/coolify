import { promises as fs } from 'fs';
import { defaultComposeConfiguration, executeCommand, generateSecrets } from '../common';
import { saveBuildLog } from './common';
import yaml from 'js-yaml';

export default async function (data) {
	let {
		applicationId,
		debug,
		buildId,
		dockerId,
		network,
		volumes,
		labels,
		workdir,
		baseDirectory,
		secrets,
		pullmergeRequestId,
		dockerComposeConfiguration,
		dockerComposeFileLocation
	} = data;
	const fileYaml = `${workdir}${baseDirectory}${dockerComposeFileLocation}`;
	const dockerComposeRaw = await fs.readFile(fileYaml, 'utf8');
	const dockerComposeYaml = yaml.load(dockerComposeRaw);
	if (!dockerComposeYaml.services) {
		throw 'No Services found in docker-compose file.';
	}
	let envs = [];
	if (secrets.length > 0) {
		envs = [...envs, ...generateSecrets(secrets, pullmergeRequestId, false, null)];
	}

	const composeVolumes = [];
	if (volumes.length > 0) {
		for (const volume of volumes) {
			let [v, path] = volume.split(':');
			composeVolumes[v] = {
				name: v
			};
		}
	}

	let networks = {};
	for (let [key, value] of Object.entries(dockerComposeYaml.services)) {
		value['container_name'] = `${applicationId}-${key}`;
		let environment = typeof value['environment'] === 'undefined' ? []  : value['environment']
		value['environment'] = [...environment, ...envs];
		value['labels'] = labels;
		// TODO: If we support separated volume for each service, we need to add it here
		if (value['volumes']?.length > 0) {
			value['volumes'] = value['volumes'].map((volume) => {
				let [v, path, permission] = volume.split(':');
				if (!path) {
					path = v;
					v = `${applicationId}${v.replace(/\//gi, '-').replace(/\./gi, '')}`;
				} else {
					v = `${applicationId}${v.replace(/\//gi, '-').replace(/\./gi, '')}`;
				}
				composeVolumes[v] = {
					name: v
				};
				return `${v}:${path}${permission ? ':' + permission : ''}`;
			});
		}
		if (volumes.length > 0) {
			for (const volume of volumes) {
				value['volumes'].push(volume);
			}
		}
		if (dockerComposeConfiguration[key].port) {
			value['expose'] = [dockerComposeConfiguration[key].port];
		}
		if (value['networks']?.length > 0) {
			value['networks'].forEach((network) => {
				networks[network] = {
					name: network
				};
			});
		}
		value['networks'] = [...(value['networks'] || ''), network];
		dockerComposeYaml.services[key] = {
			...dockerComposeYaml.services[key],
			restart: defaultComposeConfiguration(network).restart,
			deploy: defaultComposeConfiguration(network).deploy
		};
	}
	if (Object.keys(composeVolumes).length > 0) {
		dockerComposeYaml['volumes'] = { ...composeVolumes };
	}
	dockerComposeYaml['networks'] = Object.assign({ ...networks }, { [network]: { external: true } });

	await fs.writeFile(fileYaml, yaml.dump(dockerComposeYaml));
	await executeCommand({
		debug,
		buildId,
		applicationId,
		dockerId,
		command: `docker compose --project-directory ${workdir} pull`
	});
	await saveBuildLog({ line: 'Pulling images from Compose file...', buildId, applicationId });
	await executeCommand({
		debug,
		buildId,
		applicationId,
		dockerId,
		command: `docker compose --project-directory ${workdir} build --progress plain`
	});
	await saveBuildLog({ line: 'Building images from Compose file...', buildId, applicationId });
}
