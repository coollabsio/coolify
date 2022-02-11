import { dev } from '$app/env';
import { asyncExecShell, getEngine } from '$lib/common';
import { prisma } from '$lib/database';
import { defaultProxyImageHttp, defaultProxyImageTcp } from '$lib/haproxy';

export default async function () {
	if (!dev) {
		const destinationDockers = await prisma.destinationDocker.findMany();
		for (const destinationDocker of destinationDockers) {
			const host = getEngine(destinationDocker.engine);
			// Tagging images with labels
			try {
				const images = [
					`coollabsio/${defaultProxyImageTcp}`,
					`coollabsio/${defaultProxyImageHttp}`,
					'certbot/certbot:latest',
					' alpine:latest'
				];
				for (const image of images) {
					await asyncExecShell(
						`DOCKER_HOST=${host} docker pull ${image} && echo "FROM ${image}" | docker build --label coolify.managed="true" -t "${image}" -`
					);
				}
			} catch (error) {}
			try {
				await asyncExecShell(`DOCKER_HOST=${host} docker container prune -f`);
			} catch (error) {
				console.log(error);
			}
			// Cleanup images that are not managed by coolify
			try {
				await asyncExecShell(
					`DOCKER_HOST=${host} docker image prune --filter 'label!=coolify.managed=true' -a -f`
				);
			} catch (error) {
				console.log(error);
			}
		}
	}
}
