import { getDomain } from '$lib/common';
import { prisma } from '$lib/database';
import { dockerInstance } from '$lib/docker';
import {
	checkContainer,
	configureCoolifyProxyOn,
	configureProxyForApplication,
	startCoolifyProxy
} from '$lib/haproxy';
import * as db from '$lib/database';

export default async function () {
	// Check destination containers and configure proxy if needed
	const destinationDockers = await prisma.destinationDocker.findMany({});
	for (const destination of destinationDockers) {
		if (destination.isCoolifyProxyUsed) {
			const docker = dockerInstance({ destinationDocker: destination });
			const containers = await docker.engine.listContainers();
			const configurations = containers.filter((container) => container.Labels['coolify.managed']);
			for (const configuration of configurations) {
				const parsedConfiguration = JSON.parse(
					Buffer.from(configuration.Labels['coolify.configuration'], 'base64').toString()
				);
				if (configuration.Labels['coolify.type'] === 'standalone-application') {
					const { fqdn, applicationId, port } = parsedConfiguration;
					if (fqdn) {
						const domain = getDomain(fqdn);
						const isHttps = fqdn.startsWith('https://');
						await configureProxyForApplication({ domain, applicationId, port, isHttps });
					}
				}
			}
		}
	}
	// Check Coolify FQDN and configure proxy if needed
	const { fqdn } = await db.listSettings()
	if (fqdn) {
		const domain = getDomain(fqdn);
		const found = await checkContainer('/var/run/docker.sock', 'coolify-haproxy');
		if (!found) await startCoolifyProxy('/var/run/docker.sock');
		await configureCoolifyProxyOn({ domain });
	}
}
