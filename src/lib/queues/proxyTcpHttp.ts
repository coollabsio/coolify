import { ErrorHandler, generateDatabaseConfiguration, prisma } from '$lib/database';
import {
	startCoolifyProxy,
	startHttpProxy,
	startTcpProxy,
	startTraefikHTTPProxy,
	startTraefikProxy,
	startTraefikTCPProxy,
	stopTcpHttpProxy
} from '$lib/haproxy';

export default async function (): Promise<void | {
	status: number;
	body: { message: string; error: string };
}> {
	try {
		const settings = await prisma.setting.findFirst();
		// Coolify Proxy
		const localDocker = await prisma.destinationDocker.findFirst({
			where: { engine: '/var/run/docker.sock' }
		});
		if (localDocker && localDocker.isCoolifyProxyUsed) {
			if (settings.isTraefikUsed) {
				await startTraefikProxy('/var/run/docker.sock');
			} else {
				await startCoolifyProxy('/var/run/docker.sock');
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
						await stopTcpHttpProxy(destinationDocker, publicPort, `haproxy-for-${publicPort}`);
						await startTraefikTCPProxy(destinationDocker, id, publicPort, privatePort);
					} else {
						await stopTcpHttpProxy(destinationDocker, publicPort, `proxy-for-${publicPort}`);
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
							destinationDocker,
							ftpPublicPort,
							`haproxy-for-${ftpPublicPort}`
						);
						await startTraefikTCPProxy(destinationDocker, `${id}-ftp`, ftpPublicPort, 22);
					} else {
						await stopTcpHttpProxy(destinationDocker, ftpPublicPort, `proxy-for-${ftpPublicPort}`);
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
		for (const minio of minioInstances) {
			const { service, publicPort } = minio;
			const { destinationDockerId, destinationDocker, id } = service;
			if (destinationDockerId) {
				if (destinationDocker.isCoolifyProxyUsed) {
					if (settings.isTraefikUsed) {
						await stopTcpHttpProxy(destinationDocker, publicPort, `haproxy-for-${publicPort}`);
						await startTraefikHTTPProxy(destinationDocker, id, publicPort, 9000);
					} else {
						await stopTcpHttpProxy(destinationDocker, publicPort, `proxy-for-${publicPort}`);
						await startHttpProxy(destinationDocker, id, publicPort, 9000);
					}
				}
			}
		}
	} catch (error) {
		return ErrorHandler(error.response?.body || error);
	}
}
