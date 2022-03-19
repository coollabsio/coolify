import { buildImage } from '$lib/docker';
import { promises as fs } from 'fs';

const createDockerfile = async (data, image): Promise<void> => {
	const { workdir, baseDirectory } = data;
	const Dockerfile: Array<string> = [];

	Dockerfile.push(`FROM ${image}`);
	Dockerfile.push(`LABEL coolify.image=true`);
	Dockerfile.push('RUN a2enmod rewrite');
	Dockerfile.push('WORKDIR /var/www/html');
	Dockerfile.push(`COPY .${baseDirectory || ''} /var/www/html`);
	Dockerfile.push(`EXPOSE 80`);
	Dockerfile.push('CMD ["apache2-foreground"]');
	Dockerfile.push('RUN chown -R www-data /var/www/html');
	await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile.join('\n'));
};

export default async function (data) {
	try {
		const image = 'php:apache';
		await createDockerfile(data, image);
		await buildImage(data);
	} catch (error) {
		throw error;
	}
}
