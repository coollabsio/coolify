import { ErrorHandler, generateDatabaseConfiguration, prisma } from '$lib/database';
import { checkContainer, startTcpProxy } from '$lib/haproxy';

export default async function (): Promise<void | {
	status: number;
	body: { message: string; error: string };
}> {
	try {
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
			const { service, ftpPublicPort, id } = ftp;
			const { destinationDockerId, destinationDocker } = service;
			if (destinationDockerId) {
				await startTcpProxy(destinationDocker, `${id}-ftp`, ftpPublicPort, 22);
			}
		}
	} catch (error) {
		return ErrorHandler(error.response?.body || error);
	}
}
