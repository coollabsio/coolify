import cuid from 'cuid';
import crypto from 'crypto';
import { uniqueNamesGenerator, adjectives, colors, animals } from 'unique-names-generator';
import { docker } from '$lib/api/docker';
import { baseServiceConfiguration } from './common';
import { execShellAsync } from '../common';
import { promises as fs } from 'fs';
import Configuration from '$models/Configuration';
function getUniq() {
	return uniqueNamesGenerator({ dictionaries: [adjectives, animals, colors], length: 2 });
}

export function setDefaultConfiguration(configuration) {
	const nickname = configuration.general.nickname || getUniq();
	const deployId = cuid();
	const shaBase = JSON.stringify({ repository: configuration.repository, domain: configuration.publish.domain });
	const sha256 = crypto.createHash('sha256').update(shaBase).digest('hex');

	configuration.build.container.name = sha256.slice(0, 15);

	configuration.general.nickname = nickname;
	configuration.general.deployId = deployId;
	configuration.general.workdir = `/tmp/${deployId}`;
	if (configuration.general.isPreviewDeploymentEnabled && configuration.general.pullRequest !== 0) {
		configuration.build.container.name = `pr${configuration.general.pullRequest}-${sha256.slice(
			0,
			8
		)}`;
		configuration.publish.domain = `pr${configuration.general.pullRequest}.${configuration.publish.domain}`;
	}
	if (!configuration.publish.path) configuration.publish.path = '/';
	if (!configuration.publish.port) {
		if (
			configuration.build.pack === 'nodejs' ||
			configuration.build.pack === 'nuxtjs' ||
			configuration.build.pack === 'rust' ||
			configuration.build.pack === 'nextjs' ||
			configuration.build.pack === 'nestjs'
		) {
			configuration.publish.port = 3000;
		} else if (configuration.build.pack === 'python') {
			configuration.publish.port = 4000;
		} else {
			configuration.publish.port = 80;
		}
	}
	if (!configuration.build.directory) configuration.build.directory = '';
	if (configuration.build.directory.startsWith('/'))
		configuration.build.directory = configuration.build.directory.replace('/', '');

	if (!configuration.publish.directory) configuration.publish.directory = '';
	if (configuration.publish.directory.startsWith('/'))
		configuration.publish.directory = configuration.publish.directory.replace('/', '');

	if (configuration.build.pack === 'static' || configuration.build.pack === 'nodejs') {
		if (!configuration.build.command.installation)
			configuration.build.command.installation = 'yarn install';
	}
	if (
		configuration.build.pack === 'nodejs' ||
		configuration.build.pack === 'vuejs' ||
		configuration.build.pack === 'nuxtjs' ||
		configuration.build.pack === 'nextjs' ||
		configuration.build.pack === 'nestjs'
	) {
		if (!configuration.build.command.start) configuration.build.command.start = 'yarn start';
	}
	if (configuration.build.pack === 'python') {
		if (!configuration.build.command.python.module)
			configuration.build.command.python.module = 'main';
		if (!configuration.build.command.python.instance)
			configuration.build.command.python.instance = 'app';
	}

	configuration.build.container.baseSHA = crypto
		.createHash('sha256')
		.update(JSON.stringify(baseServiceConfiguration))
		.digest('hex');
	configuration.baseServiceConfiguration = baseServiceConfiguration;

	return configuration;
}

export async function precheckDeployment(configuration, originalDomain) {
	const services = await Configuration.find({
		'publish.domain': originalDomain
	}).select('-_id -__v -createdAt -updatedAt');
	// const services = (await docker.engine.listServices()).filter(
	// 	(r) =>
	// 		r.Spec.Labels.managedBy === 'coolify' &&
	// 		r.Spec.Labels.type === 'application' &&
	// 		JSON.parse(r.Spec.Labels.configuration).publish.domain === configuration.publish.domain
	// );
	let foundService = false;
	let configChanged = false;
	let imageChanged = false;
	let forceUpdate = false;
	for (const service of services) {
		// const running = JSON.parse(service.Spec.Labels.configuration);
			if (
				service.repository.id === configuration.repository.id &&
				service.repository.branch === configuration.repository.branch
			) {
				foundService = true;
				// Base service configuration changed
				if (
					!service.build.container.baseSHA ||
					service.build.container.baseSHA !== configuration.build.container.baseSHA
				) {
					forceUpdate = true;
				}
				// If the deployment is in error state, forceUpdate
				const state = await execShellAsync(
					`docker stack ps ${service.build.container.name} --format '{{ json . }}'`
				);
				const isError = state
					.split('\n')
					.filter((n) => n)
					.map((s) => JSON.parse(s))
					.filter(
						(n) =>
							n.DesiredState !== 'Running' && n.Image.split(':')[1] === service.build.container.tag
					);
				if (isError.length > 0) {
					forceUpdate = true;
				}

				const compareObjects = (a, b) => {
					if (a === b) return true;

					if (typeof a != 'object' || typeof b != 'object' || a == null || b == null) return false;

					const keysA = Object.keys(a),
						keysB = Object.keys(b);

					if (keysA.length != keysB.length) return false;

					for (const key of keysA) {
						if (!keysB.includes(key)) return false;

						if (typeof a[key] === 'function' || typeof b[key] === 'function') {
							if (a[key].toString() != b[key].toString()) return false;
						} else {
							if (!compareObjects(a[key], b[key])) return false;
						}
					}

					return true;
				};

				const runningWithoutContainer = JSON.parse(JSON.stringify(service));
				delete runningWithoutContainer.build.container;

				const configurationWithoutContainer = JSON.parse(JSON.stringify(configuration));
				delete configurationWithoutContainer.build.container;

				// If only the configuration changed
				if (
					!compareObjects(runningWithoutContainer.build, configurationWithoutContainer.build) ||
					!compareObjects(runningWithoutContainer.publish, configurationWithoutContainer.publish) ||
					runningWithoutContainer.general.isPreviewDeploymentEnabled !==
						configurationWithoutContainer.general.isPreviewDeploymentEnabled
				) {
					configChanged = true;
				}

				// If only the image changed
				if (service.build.container.tag !== configuration.build.container.tag) imageChanged = true;
				// If build pack changed, forceUpdate the service
				if (service.build.pack !== configuration.build.pack) forceUpdate = true;
				if (
					configuration.general.isPreviewDeploymentEnabled &&
					configuration.general.pullRequest !== 0
				)
					forceUpdate = true;
			}
		
	}
	if (forceUpdate) {
		imageChanged = false;
		configChanged = false;
	}
	return {
		foundService,
		imageChanged,
		configChanged,
		forceUpdate
	};
}

export async function updateServiceLabels(configuration) {
	return await execShellAsync(
		`docker service update --label-add configuration='${JSON.stringify(configuration)}' ${
			configuration.build.container.name
		}_${configuration.build.container.name}`
	);
}
