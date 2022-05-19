import { ErrorHandler, generateDatabaseConfiguration, prisma } from '$lib/database';
import {
	checkContainer,
	startCoolifyProxy,
	startHttpProxy,
	startTcpProxy,
	startTraefikHTTPProxy,
	startTraefikProxy,
	startTraefikTCPProxy,
	stopCoolifyProxy,
	stopTcpHttpProxy,
	stopTraefikProxy
} from '$lib/haproxy';

export default async function (): Promise<void | {
	status: number;
	body: { message: string; error: string };
}> {
	try {
		// Coolify Proxy
		const engine = '/var/run/docker.sock';
		const settings = await prisma.setting.findFirst();
		const localDocker = await prisma.destinationDocker.findFirst({
			where: { engine, network: 'coolify' }
		});
		if (localDocker && localDocker.isCoolifyProxyUsed) {
			if (settings.isTraefikUsed) {
				const found = await checkContainer(engine, 'coolify-haproxy');
				if (found) await stopCoolifyProxy(engine);
				await startTraefikProxy(engine);
			} else {
				const found = await checkContainer(engine, 'coolify-proxy');
				if (found) await stopTraefikProxy(engine);
				await startCoolifyProxy(engine);
			}
		}

		// TCP Proxies
		const databasesWithPublicPort = await prisma.database.findMany({
			where: { publicPort: { not: null } },
			include: { settings: true, destinationDocker: true }
		});
		for (const database of databasesWithPublicPort) {
			const { destinationDockerId, destinationDocker, publicPort, id } = database;
			if (destinationDockerId) {
				if (destinationDocker.isCoolifyProxyUsed) {
					const { privatePort } = generateDatabaseConfiguration(database);
					if (settings.isTraefikUsed) {
						await stopTcpHttpProxy(id, destinationDocker, publicPort, `haproxy-for-${publicPort}`);
						await startTraefikTCPProxy(destinationDocker, id, publicPort, privatePort);
					} else {
						await stopTcpHttpProxy(id, destinationDocker, publicPort, `${id}-${publicPort}`);
						await startTcpProxy(destinationDocker, id, publicPort, privatePort);
					}
				}
			}
		}
		const wordpressWithFtp = await prisma.wordpress.findMany({
			where: { ftpPublicPort: { not: null } },
			include: { service: { include: { destinationDocker: true } } }
		});
		for (const ftp of wordpressWithFtp) {
			const { service, ftpPublicPort } = ftp;
			const { destinationDockerId, destinationDocker, id } = service;
			if (destinationDockerId) {
				if (destinationDocker.isCoolifyProxyUsed) {
					if (settings.isTraefikUsed) {
						await stopTcpHttpProxy(
							id,
							destinationDocker,
							ftpPublicPort,
							`haproxy-for-${ftpPublicPort}`
						);
						await startTraefikTCPProxy(destinationDocker, id, ftpPublicPort, 22, 'wordpressftp');
					} else {
						await stopTcpHttpProxy(id, destinationDocker, ftpPublicPort, `${id}-${ftpPublicPort}`);
						await startTcpProxy(destinationDocker, `${id}-ftp`, ftpPublicPort, 22);
					}
				}
			}
		}

		// HTTP Proxies
		const minioInstances = await prisma.minio.findMany({
			where: { publicPort: { not: null } },
			include: { service: { include: { destinationDocker: true } } }
		});
		console.log(minioInstances);
		for (const minio of minioInstances) {
			const { service, publicPort } = minio;
			const { destinationDockerId, destinationDocker, id } = service;
			if (destinationDockerId) {
				if (destinationDocker.isCoolifyProxyUsed) {
					if (settings.isTraefikUsed) {
						await stopTcpHttpProxy(id, destinationDocker, publicPort, `haproxy-for-${publicPort}`);
						await startTraefikHTTPProxy(destinationDocker, id, publicPort, 9000);
					} else {
						await stopTcpHttpProxy(id, destinationDocker, publicPort, `${id}-${publicPort}`);
						await startHttpProxy(destinationDocker, id, publicPort, 9000);
					}
				}
			}
		}
	} catch (error) {
		console.log(error);
		return ErrorHandler(error.response?.body || error);
	}
}
