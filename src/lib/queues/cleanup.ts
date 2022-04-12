import { asyncExecShell, getEngine, version } from '$lib/common';
import { prisma } from '$lib/database';
export default async function (): Promise<void> {
	const destinationDockers = await prisma.destinationDocker.findMany();
	for (const destinationDocker of destinationDockers) {
		const host = getEngine(destinationDocker.engine);
		// Cleanup old coolify images
		try {
			let { stdout: images } = await asyncExecShell(
				`DOCKER_HOST=${host} docker images coollabsio/coolify --filter before="coollabsio/coolify:${version}" -q | xargs `
			);
			images = images.trim();
			if (images) {
				await asyncExecShell(`DOCKER_HOST=${host} docker rmi -f ${images}`);
			}
		} catch (error) {
			//console.log(error);
		}
		try {
			await asyncExecShell(`DOCKER_HOST=${host} docker container prune -f`);
		} catch (error) {
			//console.log(error);
		}
		try {
			await asyncExecShell(`DOCKER_HOST=${host} docker image prune -f --filter "until=2h"`);
		} catch (error) {
			//console.log(error);
		}
		// Cleanup old images older than a day
		try {
			await asyncExecShell(`DOCKER_HOST=${host} docker image prune --filter "until=24h" -a -f`);
		} catch (error) {
			//console.log(error);
		}
	}
}
