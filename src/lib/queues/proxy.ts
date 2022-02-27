import { getDomain } from '$lib/common';
import { getApplicationById, prisma, supportedServiceTypesAndVersions } from '$lib/database';
import { dockerInstance } from '$lib/docker';
import {
	checkContainer,
	configureCoolifyProxyOn,
	configureProxyForApplication,
	configureSimpleServiceProxyOn,
	forceSSLOnApplication,
	reloadHaproxy,
	setWwwRedirection,
	startCoolifyProxy,
	startHttpProxy
} from '$lib/haproxy';
import * as db from '$lib/database';
// import { generateRemoteEngine } from '$lib/components/common';

export default async function () {
	try {
		// Check destination containers and configure proxy if needed
		const destinationDockers = await prisma.destinationDocker.findMany({});
		for (const destination of destinationDockers) {
			if (destination.isCoolifyProxyUsed) {
				// if (destination.remoteEngine) {
				// 	const engine = generateRemoteEngine(destination);
				// }
				const docker = dockerInstance({ destinationDocker: destination });
				const containers = await docker.engine.listContainers();
				const configurations = containers.filter(
					(container) => container.Labels['coolify.managed']
				);
				for (const configuration of configurations) {
					if (configuration.Labels['coolify.configuration']) {
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
									if (isHttps) await forceSSLOnApplication(domain);
									await setWwwRedirection(fqdn);
								}
							}
						}
					}
				}
				for (const container of containers) {
					const image = container.Image.split(':')[0];
					const found = supportedServiceTypesAndVersions.find((a) => a.baseImage === image);
					if (found) {
						const type = found.name;
						const mainPort = found.ports.main;
						const id = container.Names[0].replace('/', '');
						const service = await db.prisma.service.findUnique({
							where: { id },
							include: {
								destinationDocker: true,
								minio: true,
								plausibleAnalytics: true,
								vscodeserver: true,
								wordpress: true
							}
						});
						const { fqdn } = service;
						const domain = getDomain(fqdn);
						await configureSimpleServiceProxyOn({ id, domain, port: mainPort });
						const publicPort = service[type]?.publicPort;
						if (publicPort) {
							const containerFound = await checkContainer(
								destination.engine,
								`haproxy-for-${publicPort}`
							);
							if (!containerFound) {
								await startHttpProxy(destination, id, publicPort, 9000);
							}
						}
					}
				}
			}
		}
		const services = await prisma.service.findMany({});
		// Check Coolify FQDN and configure proxy if needed
		const { fqdn } = await db.listSettings();
		if (fqdn) {
			const domain = getDomain(fqdn);
			await startCoolifyProxy('/var/run/docker.sock');
			await configureCoolifyProxyOn(fqdn);
			await setWwwRedirection(fqdn);
			const isHttps = fqdn.startsWith('https://');
			if (isHttps) await forceSSLOnApplication(domain);
		}
	} catch (error) {
		throw error;
	}
}
