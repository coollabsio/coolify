import { ErrorHandler, generateDatabaseConfiguration, prisma } from '$lib/database';
import { startCoolifyProxy, startHttpProxy, startTcpProxy } from '$lib/haproxy';

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
		console.log(settings.disableHaproxy);
		if (localDocker && localDocker.isCoolifyProxyUsed && !settings.disableHaproxy) {
			console.log('asd');
			await startCoolifyProxy('/var/run/docker.sock');
		}
		// TCP Proxies
		const databasesWithPublicPort = await prisma.database.findMany({
			where: { publicPort: { not: null } },
			include: { settings: true, destinationDocker: true }
		});
		for (const database of databasesWithPublicPort) {
			const { destinationDockerId, destinationDocker, publicPort, id } = database;
			if (destinationDockerId) {
				const { privatePort } = generateDatabaseConfiguration(database);
				await startTcpProxy(destinationDocker, id, publicPort, privatePort);
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
				await startTcpProxy(destinationDocker, `${id}-ftp`, ftpPublicPort, 22);
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
				await startHttpProxy(destinationDocker, id, publicPort, 9000);
			}
		}
	} catch (error) {
		return ErrorHandler(error.response?.body || error);
	}
}
