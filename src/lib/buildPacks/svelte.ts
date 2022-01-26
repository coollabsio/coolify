import { buildCacheImageWithNode, buildImage } from '$lib/docker';
import { promises as fs } from 'fs';

const createDockerfile = async (data, image): Promise<void> => {
	const { applicationId, tag, workdir, publishDirectory } = data;
	const Dockerfile: Array<string> = [];

	Dockerfile.push(`FROM ${image}`);
	Dockerfile.push('WORKDIR /usr/share/nginx/html');
	Dockerfile.push(`COPY --from=${applicationId}:${tag}-cache /usr/src/app/${publishDirectory} ./`);
	Dockerfile.push(`EXPOSE 80`);
	Dockerfile.push('CMD ["nginx", "-g", "daemon off;"]');
	await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile.join('\n'));
};

export default async function (data) {
	try {
		const image = 'nginx:stable-alpine';
		const imageForBuild = 'node:lts';

		await buildCacheImageWithNode(data, imageForBuild);
		await createDockerfile(data, image);
		await buildImage(data);
	} catch (error) {
		throw error;
	}
}
