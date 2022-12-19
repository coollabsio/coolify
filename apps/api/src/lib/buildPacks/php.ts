import { promises as fs } from 'fs';
import { buildImage } from './common';

const createDockerfile = async (data, image, htaccessFound): Promise<void> => {
	const { workdir, baseDirectory, buildId, port, secrets, pullmergeRequestId } = data;
	const Dockerfile: Array<string> = [];
	let composerFound = false;
	try {
		await fs.readFile(`${workdir}${baseDirectory || ''}/composer.json`);
		composerFound = true;
	} catch (error) {}

	Dockerfile.push(`FROM ${image}`);
	Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
	if (secrets.length > 0) {
		secrets.forEach((secret) => {
			if (secret.isBuildSecret) {
				if (pullmergeRequestId) {
					const isSecretFound = secrets.filter(s => s.name === secret.name && s.isPRMRSecret)
					if (isSecretFound.length > 0) {
						Dockerfile.push(`ARG ${secret.name}='${isSecretFound[0].value}'`);
					} else {
						Dockerfile.push(`ARG ${secret.name}='${secret.value}'`);
					}
				} else {
					if (!secret.isPRMRSecret) {
						Dockerfile.push(`ARG ${secret.name}='${secret.value}'`);
					}
				}
			}
		});
	}
	Dockerfile.push('WORKDIR /app');
	Dockerfile.push(`COPY .${baseDirectory || ''} /app`);
	if (htaccessFound) {
		Dockerfile.push(`COPY .${baseDirectory || ''}/.htaccess ./`);
	}
	if (composerFound) {
		Dockerfile.push(`RUN composer install`);
	}

	Dockerfile.push(`COPY /entrypoint.sh /opt/docker/provision/entrypoint.d/30-entrypoint.sh`);
	Dockerfile.push(`EXPOSE ${port}`);
	await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile.join('\n'));
};

export default async function (data) {
	const { workdir, baseDirectory, baseImage } = data;
	try {
		let htaccessFound = false;
		try {
			await fs.readFile(`${workdir}${baseDirectory || ''}/.htaccess`);
			htaccessFound = true;
		} catch (e) {
			//
		}
		await createDockerfile(data, baseImage, htaccessFound);
		await buildImage(data);
	} catch (error) {
		throw error;
	}
}
