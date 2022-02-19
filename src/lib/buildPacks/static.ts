import { buildCacheImageWithNode, buildImage } from '$lib/docker';
import { promises as fs } from 'fs';

const createDockerfile = async (data, image): Promise<void> => {
	const {
		applicationId,
		tag,
		workdir,
		buildCommand,
		baseDirectory,
		publishDirectory,
		secrets,
		pullmergeRequestId
	} = data;
	const Dockerfile: Array<string> = [];

	Dockerfile.push(`FROM ${image}`);
	Dockerfile.push('WORKDIR /usr/share/nginx/html');
	if (secrets.length > 0) {
		secrets.forEach((secret) => {
			if (secret.isBuildSecret) {
				if (pullmergeRequestId) {
					if (secret.isPRMRSecret) {
						Dockerfile.push(`ARG ${secret.name} ${secret.value}`);
					}
				} else {
					if (!secret.isPRMRSecret) {
						Dockerfile.push(`ARG ${secret.name} ${secret.value}`);
					}
				}
			}
		});
	}
	if (buildCommand) {
		Dockerfile.push(
			`COPY --from=${applicationId}:${tag}-cache /usr/src/app/${publishDirectory} ./`
		);
	} else {
		Dockerfile.push(`COPY ./${baseDirectory || ''} ./`);
	}
	Dockerfile.push(`EXPOSE 80`);
	Dockerfile.push('CMD ["nginx", "-g", "daemon off;"]');
	await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile.join('\n'));
};

export default async function (data) {
	try {
		const image = 'nginx:stable-alpine';
		const imageForBuild = 'node:lts';
		if (data.buildCommand) await buildCacheImageWithNode(data, imageForBuild);
		await createDockerfile(data, image);
		await buildImage(data);
	} catch (error) {
		throw error;
	}
}
