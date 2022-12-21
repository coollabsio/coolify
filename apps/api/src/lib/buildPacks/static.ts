import { promises as fs } from 'fs';
import { generateSecrets } from '../common';
import { buildCacheImageWithNode, buildImage } from './common';

const createDockerfile = async (data, image): Promise<void> => {
	const {
		applicationId,
		tag,
		workdir,
		buildCommand,
		baseDirectory,
		publishDirectory,
		secrets,
		pullmergeRequestId,
		baseImage,
		buildId,
		port
	} = data;
	const Dockerfile: Array<string> = [];

	Dockerfile.push(`FROM ${image}`);
	if (baseImage?.includes('httpd')) {
		Dockerfile.push('WORKDIR /usr/local/apache2/htdocs/');
	} else {
		Dockerfile.push('WORKDIR /app');
	}
	Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
	if (secrets.length > 0) {
		generateSecrets(secrets, pullmergeRequestId, true).forEach((env) => {
			Dockerfile.push(env);
		});
	}
	if (buildCommand) {
		Dockerfile.push(`COPY --from=${applicationId}:${tag}-cache /app/${publishDirectory} ./`);
	} else {
		Dockerfile.push(`COPY .${baseDirectory || ''} ./`);
	}
	if (baseImage?.includes('nginx')) {
		Dockerfile.push(`COPY /nginx.conf /etc/nginx/nginx.conf`);
	}
	Dockerfile.push(`EXPOSE ${port}`);
	await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile.join('\n'));
};

export default async function (data) {
	try {
		const { baseImage, baseBuildImage } = data;
		if (data.buildCommand) await buildCacheImageWithNode(data, baseBuildImage);
		await createDockerfile(data, baseImage);
		await buildImage(data);
	} catch (error) {
		throw error;
	}
}
