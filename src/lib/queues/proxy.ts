import { getDomain } from '$lib/common';
import { getApplicationById, prisma } from '$lib/database';
import { dockerInstance } from '$lib/docker';
import {
	checkContainer,
	configureCoolifyProxyOn,
	configureProxyForApplication,
	forceSSLOnApplication,
	reloadHaproxy,
	setWwwRedirection,
	startCoolifyProxy
} from '$lib/haproxy';
import * as db from '$lib/database';

export default async function () {
	try {
		// Check destination containers and configure proxy if needed
		const destinationDockers = await prisma.destinationDocker.findMany({});
		for (const destination of destinationDockers) {
			if (destination.isCoolifyProxyUsed) {
				const docker = dockerInstance({ destinationDocker: destination });
				const containers = await docker.engine.listContainers();
				const configurations = containers.filter(
					(container) => container.Labels['coolify.managed']
				);
				for (const configuration of configurations) {
					const parsedConfiguration = JSON.parse(
						Buffer.from(configuration.Labels['coolify.configuration'], 'base64').toString()
					);
					if (
						parsedConfiguration &&
						configuration.Labels['coolify.type'] === 'standalone-application'
					) {
						const { fqdn, applicationId, port, pullmergeRequestId } = parsedConfiguration;
						if (fqdn) {
							const found = await getApplicationById({ id: applicationId });
							if (found) {
								const domain = getDomain(fqdn);
								await configureProxyForApplication({
									domain,
									imageId: pullmergeRequestId
										? `${applicationId}-${pullmergeRequestId}`
										: applicationId,
									applicationId,
									port
								});
								const isHttps = fqdn.startsWith('https://');
								if (isHttps) await forceSSLOnApplication({ domain });
								await setWwwRedirection(fqdn);
							}
						}
					}
				}
			}
		}
		// Check Coolify FQDN and configure proxy if needed
		const { fqdn } = await db.listSettings();
		if (fqdn) {
			const domain = getDomain(fqdn);
			await startCoolifyProxy('/var/run/docker.sock');
			await configureCoolifyProxyOn(fqdn);
			await setWwwRedirection(fqdn);
			const isHttps = fqdn.startsWith('https://');
			if (isHttps) await forceSSLOnApplication({ domain });
		}
	} catch (error) {
		console.log(error);
		throw error;
	}
}
