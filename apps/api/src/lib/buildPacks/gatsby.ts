import { promises as fs } from 'fs';
import { buildCacheImageWithNode, buildImage } from './common';

const createDockerfile = async (data, imageforBuild): Promise<void> => {
	const { applicationId, tag, workdir, publishDirectory, baseImage, buildId, port } = data;
	const Dockerfile: Array<string> = [];

	Dockerfile.push(`FROM ${imageforBuild}`);
	Dockerfile.push('WORKDIR /app');
	Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
	Dockerfile.push(`COPY --from=${applicationId}:${tag}-cache /app/${publishDirectory} ./`);
	if (baseImage?.includes('nginx')) {
		Dockerfile.push(`COPY /nginx.conf /etc/nginx/nginx.conf`);
	}
	Dockerfile.push(`EXPOSE ${port}`);
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
