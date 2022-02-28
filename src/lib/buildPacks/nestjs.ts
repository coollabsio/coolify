import { buildCacheImageWithNode, buildImage } from '$lib/docker';
import { promises as fs } from 'fs';

const createDockerfile = async (data, image): Promise<void> => {
	const { applicationId, tag, port, startCommand, workdir, baseDirectory } = data;
	const Dockerfile: Array<string> = [];

	Dockerfile.push(`FROM ${image}`);
	Dockerfile.push('WORKDIR /usr/src/app');
	Dockerfile.push(`LABEL coolify.image=true`);
	Dockerfile.push(
		`COPY --from=${applicationId}:${tag}-cache /usr/src/app/${baseDirectory || ''} ./`
	);
	Dockerfile.push(`EXPOSE ${port}`);
	Dockerfile.push(`CMD ${startCommand}`);
	await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile.join('\n'));
};

export default async function (data) {
	try {
		const image = 'node:lts';
		const imageForBuild = 'node:lts';

		await buildCacheImageWithNode(data, imageForBuild);
		await createDockerfile(data, image);
		await buildImage(data);
	} catch (error) {
		throw error;
	}
}
