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
	const baseDir = `${workdir}${baseDirectory}`;
	const envFile = `${baseDir}/.env`;
	const fileYaml = `${baseDir}${dockerComposeFileLocation}`;
	const dockerComposeRaw = await fs.readFile(fileYaml, 'utf8');
	const dockerComposeYaml = yaml.load(dockerComposeRaw);
	if (!dockerComposeYaml.services) {
		throw 'No Services found in docker-compose file.';
	}
	let envs = [];
	let buildEnvs = [];
	if (secrets.length > 0) {
		envs = [...envs, ...generateSecrets(secrets, pullmergeRequestId, false, null)];
		buildEnvs = [...buildEnvs, ...generateSecrets(secrets, pullmergeRequestId, true, null, true)];
	}
	await fs.writeFile(envFile, envs.join('\n'));
	const composeVolumes = [];
	if (volumes.length > 0) {
		for (const volume of volumes) {
			let [v, path] = volume.split(':');
			if (!v.startsWith('.') && !v.startsWith('..') && !v.startsWith('/') && !v.startsWith('~')) {
				composeVolumes[v] = {
					name: v
				};
			}
		}
	}
	let networks = {};
	for (let [key, value] of Object.entries(dockerComposeYaml.services)) {
		value['container_name'] = `${applicationId}-${key}`;

		if (value['env_file']) {
			delete value['env_file'];
		}
		value['env_file'] = [envFile];

		// let environment = typeof value['environment'] === 'undefined' ? [] : value['environment'];
		// let finalEnvs = [...envs];
		// if (Object.keys(environment).length > 0) {
		// 	for (const arg of Object.keys(environment)) {
		// 		const [key, _] = arg.split('=');
		// 		if (finalEnvs.filter((env) => env.startsWith(key)).length === 0) {
		// 			finalEnvs.push(arg);
		// 		}
		// 	}
		// }
		// value['environment'] = [...finalEnvs];

		let build = typeof value['build'] === 'undefined' ? [] : value['build'];
		if (typeof build === 'string') {
			build = { context: build };
		}
		const buildArgs = typeof build['args'] === 'undefined' ? [] : build['args'];
		let finalBuildArgs = [...buildEnvs];
		if (Object.keys(buildArgs).length > 0) {
			for (const arg of Object.keys(buildArgs)) {
				const [key, _] = arg.split('=');
				if (finalBuildArgs.filter((env) => env.startsWith(key)).length === 0) {
					finalBuildArgs.push(arg);
				}
			}
		}
		if (build.length > 0 || buildArgs.length > 0) {
			value['build'] = {
				...build,
				args: finalBuildArgs
			};
		}

		value['labels'] = labels;
		// TODO: If we support separated volume for each service, we need to add it here
		if (value['volumes']?.length > 0) {
			value['volumes'] = value['volumes'].map((volume) => {
				if (typeof volume === 'string') {
					let [v, path, permission] = volume.split(':');
					if (
						v.startsWith('.') ||
						v.startsWith('..') ||
						v.startsWith('/') ||
						v.startsWith('~') ||
						v.startsWith('$PWD')
					) {
						v = v
							.replace(/^\./, `~`)
							.replace(/^\.\./, '~')
							.replace(/^\$PWD/, '~');
					} else {
						if (!path) {
							path = v;
							v = `${applicationId}${v.replace(/\//gi, '-').replace(/\./gi, '')}`;
						} else {
							v = `${applicationId}${v.replace(/\//gi, '-').replace(/\./gi, '')}`;
						}
						composeVolumes[v] = {
							name: v
						};
					}
					return `${v}:${path}${permission ? ':' + permission : ''}`;
				}
				if (typeof volume === 'object') {
					let { source, target, mode } = volume;
					if (
						source.startsWith('.') ||
						source.startsWith('..') ||
						source.startsWith('/') ||
						source.startsWith('~') ||
						source.startsWith('$PWD')
					) {
						source = source
							.replace(/^\./, `~`)
							.replace(/^\.\./, '~')
							.replace(/^\$PWD/, '~');
					} else {
						if (!target) {
							target = source;
							source = `${applicationId}${source.replace(/\//gi, '-').replace(/\./gi, '')}`;
						} else {
							source = `${applicationId}${source.replace(/\//gi, '-').replace(/\./gi, '')}`;
						}
					}

					return `${source}:${target}${mode ? ':' + mode : ''}`;
				}
			});
		}
		if (volumes.length > 0) {
			for (const volume of volumes) {
				value['volumes'].push(volume);
			}
		}
		if (dockerComposeConfiguration[key]?.port) {
			value['expose'] = [dockerComposeConfiguration[key].port];
		}
		value['networks'] = [network];
		if (value['build']?.network) {
			delete value['build']['network'];
		}
		// if (value['networks']?.length > 0) {
		// 	value['networks'].forEach((network) => {
		// 		networks[network] = {
		// 			name: network
		// 		};
		// 	});
		// 	value['networks'] = [...(value['networks'] || ''), network];
		// } else {
		// 	value['networks'] = [network];
		// }

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
		command: `docker compose --project-directory ${workdir} -f ${fileYaml} pull`
	});
	await saveBuildLog({ line: 'Pulling images from Compose file...', buildId, applicationId });
	await executeCommand({
		debug,
		buildId,
		applicationId,
		dockerId,
		command: `docker compose --project-directory ${workdir} -f ${fileYaml} build --progress plain`
	});
	await saveBuildLog({ line: 'Building images from Compose file...', buildId, applicationId });
}
