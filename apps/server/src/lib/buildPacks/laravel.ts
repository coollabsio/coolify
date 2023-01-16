import { promises as fs } from 'fs';
import { generateSecrets } from '../common';
import { buildCacheImageForLaravel, buildImage } from './common';

const createDockerfile = async (data, image): Promise<void> => {
	const { workdir, applicationId, tag, buildId, port, secrets, pullmergeRequestId } = data;
	const Dockerfile: Array<string> = [];

	Dockerfile.push(`FROM ${image}`);
	Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
	if (secrets.length > 0) {
		generateSecrets(secrets, pullmergeRequestId, true).forEach((env) => {
			Dockerfile.push(env);
		});
	}
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
	Dockerfile.push(`EXPOSE ${port}`);
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
