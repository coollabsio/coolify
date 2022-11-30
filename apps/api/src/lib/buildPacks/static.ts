import { promises as fs } from 'fs';
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
		secrets.forEach((secret) => {
			if (secret.isBuildSecret) {
				if (pullmergeRequestId) {
					const isSecretFound = secrets.filter(s => s.name === secret.name && s.isPRMRSecret)
					if (isSecretFound.length > 0) {
						Dockerfile.push(`ARG ${secret.name}=${isSecretFound[0].value}`);
					} else {
						Dockerfile.push(`ARG ${secret.name}=${secret.value}`);
					}
				} else {
					if (!secret.isPRMRSecret) {
						Dockerfile.push(`ARG ${secret.name}=${secret.value}`);
					}
				}
			}
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
