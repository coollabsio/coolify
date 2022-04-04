import { buildImage } from '$lib/docker';
import { promises as fs } from 'fs';

const createDockerfile = async (data, image, htaccessFound): Promise<void> => {
	const { workdir, baseDirectory } = data;
	const Dockerfile: Array<string> = [];
	Dockerfile.push(`FROM ${image}`);
	Dockerfile.push(`LABEL coolify.image=true`);
	Dockerfile.push('WORKDIR /app');
	Dockerfile.push(`COPY .${baseDirectory || ''} /app`);
	if (htaccessFound) {
		Dockerfile.push(`COPY .${baseDirectory || ''}/.htaccess ./`);
	}
	Dockerfile.push(`COPY /entrypoint.sh /opt/docker/provision/entrypoint.d/30-entrypoint.sh`);
	Dockerfile.push(`EXPOSE 80`);
	await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile.join('\n'));
};

export default async function (data) {
	const { workdir, baseDirectory } = data;
	try {
		let htaccessFound = false;
		try {
			const d = await fs.readFile(`${workdir}${baseDirectory || ''}/.htaccess`);
			htaccessFound = true;
		} catch (e) {
			//
		}
		const image = htaccessFound ? 'webdevops/php-apache' : 'webdevops/php-nginx';
		await createDockerfile(data, image, htaccessFound);
		await buildImage(data);
	} catch (error) {
		throw error;
	}
}
