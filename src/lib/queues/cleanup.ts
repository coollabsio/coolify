import { dev } from '$app/env';
import { asyncExecShell, getEngine } from '$lib/common';
import { prisma } from '$lib/database';

export default async function () {
	if (!dev) {
		const destinationDockers = await prisma.destinationDocker.findMany();
		for (const destinationDocker of destinationDockers) {
			const host = getEngine(destinationDocker.engine);
			try {
				// await asyncExecShell(`DOCKER_HOST=${host} docker container prune -f`);
			} catch (error) {
				//
				console.log(error);
			}
			try {
				// await asyncExecShell(`DOCKER_HOST=${host} docker image prune -f`);
			} catch (error) {
				//
				console.log(error);
			}
		}
	}
}
