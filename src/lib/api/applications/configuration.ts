import cuid from 'cuid';
import crypto from 'crypto';
import { uniqueNamesGenerator, adjectives, colors, animals } from 'unique-names-generator';
import { docker } from '$lib/api/docker';
import { baseServiceConfiguration } from './common';
import { execShellAsync } from '../common';

function getUniq() {
	return uniqueNamesGenerator({ dictionaries: [adjectives, animals, colors], length: 2 });
}

export function setDefaultConfiguration(configuration) {
	const nickname = getUniq();
	const deployId = cuid();

	const shaBase = JSON.stringify({ repository: configuration.repository });
	const sha256 = crypto.createHash('sha256').update(shaBase).digest('hex');

	configuration.build.container.name = sha256.slice(0, 15);

	configuration.general.nickname = nickname;
	configuration.general.deployId = deployId;
	configuration.general.workdir = `/tmp/${deployId}`;

	if (!configuration.publish.path) configuration.publish.path = '/';
	if (!configuration.publish.port) {
		if (
			configuration.build.pack === 'nodejs' ||
			configuration.build.pack === 'vuejs' ||
			configuration.build.pack === 'nuxtjs' ||
			configuration.build.pack === 'rust' ||
			configuration.build.pack === 'nextjs'
		) {
			configuration.publish.port = 3000;
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

	configuration.build.container.baseSHA = crypto
		.createHash('sha256')
		.update(JSON.stringify(baseServiceConfiguration))
		.digest('hex');
	configuration.baseServiceConfiguration = baseServiceConfiguration;

	return configuration;
}

export async function precheckDeployment({ services, configuration }) {
	let foundService = false;
	let configChanged = false;
	let imageChanged = false;

	let forceUpdate = false;

	for (const service of services) {
		const running = JSON.parse(service.Spec.Labels.configuration);
		if (running) {
			if (
				running.repository.id === configuration.repository.id &&
				running.repository.branch === configuration.repository.branch
			) {
				// Base service configuration changed
				if (
					!running.build.container.baseSHA ||
					running.build.container.baseSHA !== configuration.build.container.baseSHA
				) {
					forceUpdate = true;
				}
				// If the deployment is in error state, forceUpdate
				const state = await execShellAsync(
					`docker stack ps ${running.build.container.name} --format '{{ json . }}'`
				);
				const isError = state
					.split('\n')
					.filter((n) => n)
					.map((s) => JSON.parse(s))
					.filter(
						(n) =>
							n.DesiredState !== 'Running' && n.Image.split(':')[1] === running.build.container.tag
					);
				if (isError.length > 0) forceUpdate = true;
				foundService = true;

				const runningWithoutContainer = JSON.parse(JSON.stringify(running));
				delete runningWithoutContainer.build.container;

				const configurationWithoutContainer = JSON.parse(JSON.stringify(configuration));
				delete configurationWithoutContainer.build.container;

				// If only the configuration changed
				if (
					JSON.stringify(runningWithoutContainer.build) !==
						JSON.stringify(configurationWithoutContainer.build) ||
					JSON.stringify(runningWithoutContainer.publish) !==
						JSON.stringify(configurationWithoutContainer.publish)
				)
					configChanged = true;
				// If only the image changed
				if (running.build.container.tag !== configuration.build.container.tag) imageChanged = true;
				// If build pack changed, forceUpdate the service
				if (running.build.pack !== configuration.build.pack) forceUpdate = true;
			}
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
	// In case of any failure during deployment, still update the current configuration.
	const services = (await docker.engine.listServices()).filter(
		(r) => r.Spec.Labels.managedBy === 'coolify' && r.Spec.Labels.type === 'application'
	);
	const found = services.find((s) => {
		const config = JSON.parse(s.Spec.Labels.configuration);
		if (
			config.repository.id === configuration.repository.id &&
			config.repository.branch === configuration.repository.branch
		) {
			return config;
		}
		return null;
	});
	if (found) {
		const { ID } = found;
		const Labels = { ...JSON.parse(found.Spec.Labels.configuration), ...configuration };
		await execShellAsync(
			`docker service update --label-add configuration='${JSON.stringify(
				Labels
			)}' --label-add com.docker.stack.image='${configuration.build.container.name}:${
				configuration.build.container.tag
			}' ${ID}`
		);
	}
}
