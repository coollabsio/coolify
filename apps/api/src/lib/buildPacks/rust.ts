import { promises as fs } from 'fs';
import TOML from '@iarna/toml';
import {  executeCommand } from '../common';
import { buildCacheImageWithCargo, buildImage } from './common';

const createDockerfile = async (data, image, name): Promise<void> => {
	const { workdir, port, applicationId, tag, buildId } = data;
	const Dockerfile: Array<string> = [];
	Dockerfile.push(`FROM ${image}`);
	Dockerfile.push('WORKDIR /app');
	Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
	Dockerfile.push(`COPY --from=${applicationId}:${tag}-cache /app/target target`);
	Dockerfile.push(`COPY --from=${applicationId}:${tag}-cache /usr/local/cargo /usr/local/cargo`);
	Dockerfile.push(`COPY . .`);
	Dockerfile.push(`RUN cargo build --release --bin ${name}`);
	Dockerfile.push('FROM debian:buster-slim');
	Dockerfile.push('WORKDIR /app');
	Dockerfile.push(
		`RUN apt-get update -y && apt-get install -y --no-install-recommends openssl libcurl4 ca-certificates && apt-get autoremove -y && apt-get clean -y && rm -rf /var/lib/apt/lists/*`
	);
	Dockerfile.push(`RUN update-ca-certificates`);
	Dockerfile.push(`COPY --from=${applicationId}:${tag}-cache /app/target/release/${name} ${name}`);
	Dockerfile.push(`EXPOSE ${port}`);
	Dockerfile.push(`CMD ["/app/${name}"]`);
	await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile.join('\n'));
};

export default async function (data) {
	try {
		const { workdir, baseImage, baseBuildImage } = data;
		const { stdout: cargoToml } = await executeCommand({ command: `cat ${workdir}/Cargo.toml` });
		const parsedToml: any = TOML.parse(cargoToml);
		const name = parsedToml.package.name;
		await buildCacheImageWithCargo(data, baseBuildImage);
		await createDockerfile(data, baseImage, name);
		await buildImage(data);
	} catch (error) {
		throw error;
	}
}
