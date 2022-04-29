import { buildCacheImageForLaravel, buildImage } from '$lib/docker';
import { promises as fs } from 'fs';

const createDockerfile = async (data, image): Promise<void> => {
	const { workdir, applicationId, tag, baseImage } = data;
	const Dockerfile: Array<string> = [];

	Dockerfile.push(`FROM ${image}`);
	Dockerfile.push(`LABEL coolify.image=true`);
	Dockerfile.push('WORKDIR /app');
	Dockerfile.push(`ENV WEB_DOCUMENT_ROOT /app/public`);
	Dockerfile.push(`COPY --chown=application:application composer.* ./`);
	Dockerfile.push(`COPY --chown=application:application database/ database/`);
	Dockerfile.push(
		`RUN composer install --ignore-platform-reqs --no-interaction --no-plugins --no-scripts --prefer-dist`
	);
	Dockerfile.push(
		`COPY --chown=application:application --from=${applicationId}:${tag}-cache /app/public/js/ /app/public/js/`
	);
	Dockerfile.push(
		`COPY --chown=application:application --from=${applicationId}:${tag}-cache /app/public/css/ /app/public/css/`
	);
	Dockerfile.push(
		`COPY --chown=application:application --from=${applicationId}:${tag}-cache /app/mix-manifest.json /app/public/mix-manifest.json`
	);
	Dockerfile.push(`COPY --chown=application:application . ./`);
	Dockerfile.push(`EXPOSE 80`);
	await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile.join('\n'));
};

export default async function (data) {
	const { baseImage, baseBuildImage } = data;
	try {
		await buildCacheImageForLaravel(data, baseBuildImage);
		await createDockerfile(data, baseImage);
		await buildImage(data);
	} catch (error) {
		throw error;
	}
}
