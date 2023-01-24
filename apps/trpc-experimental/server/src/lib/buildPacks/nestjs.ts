import { promises as fs } from 'fs';
import { buildCacheImageWithNode, buildImage } from './common';

const createDockerfile = async (data, image): Promise<void> => {
	const { buildId, applicationId, tag, port, startCommand, workdir, baseDirectory } = data;
	const Dockerfile: Array<string> = [];
	const isPnpm = startCommand.includes('pnpm');

	Dockerfile.push(`FROM ${image}`);
	Dockerfile.push('WORKDIR /app');
	Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
	if (isPnpm) {
		Dockerfile.push('RUN curl -f https://get.pnpm.io/v6.16.js | node - add --global pnpm@7');
	}
	Dockerfile.push(`COPY --from=${applicationId}:${tag}-cache /app/${baseDirectory || ''} ./`);

	Dockerfile.push(`EXPOSE ${port}`);
	Dockerfile.push(`CMD ${startCommand}`);
	await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile.join('\n'));
};

export default async function (data) {
	try {
		const { baseImage, baseBuildImage } = data;
		await buildCacheImageWithNode(data, baseBuildImage);
		await createDockerfile(data, baseImage);
		await buildImage(data);
	} catch (error) {
		throw error;
	}
}
