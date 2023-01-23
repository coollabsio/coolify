import { prisma } from '../prisma';
import { generateRangeArray } from './common';

export async function getFreeSSHLocalPort(id: string): Promise<number | boolean> {
	const { default: isReachable } = await import('is-port-reachable');
	const { remoteIpAddress, sshLocalPort } = await prisma.destinationDocker.findUnique({
		where: { id }
	});
	if (sshLocalPort) {
		return Number(sshLocalPort);
	}

	const data = await prisma.setting.findFirst();
	const { minPort, maxPort } = data;

	const ports = await prisma.destinationDocker.findMany({
		where: { sshLocalPort: { not: null }, remoteIpAddress: { not: remoteIpAddress } }
	});

	const alreadyConfigured = await prisma.destinationDocker.findFirst({
		where: {
			remoteIpAddress,
			id: { not: id },
			sshLocalPort: { not: null }
		}
	});
	if (alreadyConfigured?.sshLocalPort) {
		await prisma.destinationDocker.update({
			where: { id },
			data: { sshLocalPort: alreadyConfigured.sshLocalPort }
		});
		return Number(alreadyConfigured.sshLocalPort);
	}
	const range = generateRangeArray(minPort, maxPort);
	const availablePorts = range.filter((port) => !ports.map((p) => p.sshLocalPort).includes(port));
	for (const port of availablePorts) {
		const found = await isReachable(port, { host: 'localhost' });
		if (!found) {
			await prisma.destinationDocker.update({
				where: { id },
				data: { sshLocalPort: Number(port) }
			});
			return Number(port);
		}
	}
	return false;
}
