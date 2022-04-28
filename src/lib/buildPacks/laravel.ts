import { buildCacheImageForLaravel, buildImage } from '$lib/docker';
import { promises as fs } from 'fs';

const createDockerfile = async (data, image): Promise<void> => {
	const { workdir, applicationId, tag } = data;
	const Dockerfile: Array<string> = [];

	Dockerfile.push(`FROM ${image}`);
	Dockerfile.push(`LABEL coolify.image=true`);
	Dockerfile.push('WORKDIR /app');
	Dockerfile.push(`COPY /nginx.conf /etc/nginx/nginx.conf`);
	Dockerfile.push(`COPY composer.* ./`);
	Dockerfile.push(`COPY database/ database/`);
	Dockerfile.push(
		`RUN composer install --ignore-platform-reqs --no-interaction --no-plugins --no-scripts --prefer-dist`
	);
	Dockerfile.push(`COPY --from=${applicationId}:${tag}-cache /app/public/js/ /app/public/js/`);
	Dockerfile.push(`COPY --from=${applicationId}:${tag}-cache /app/public/css/ /app/public/css/`);
	Dockerfile.push(
		`COPY --from=${applicationId}:${tag}-cache /app/mix-manifest.json /app/public/mix-manifest.json`
	);
	Dockerfile.push(`COPY . ./`);
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
