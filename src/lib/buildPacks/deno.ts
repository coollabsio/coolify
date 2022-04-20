import { buildImage } from '$lib/docker';
import { promises as fs } from 'fs';

const createDockerfile = async (data, image): Promise<void> => {
	const { workdir, port, baseDirectory, secrets, pullmergeRequestId, denoMainFile, denoOptions } =
		data;
	const Dockerfile: Array<string> = [];

	let depsFound = false;
	try {
		await fs.readFile(`${workdir}${baseDirectory || ''}/deps.ts`);
		depsFound = true;
	} catch (error) {}

	Dockerfile.push(`FROM ${image}`);
	Dockerfile.push('WORKDIR /app');
	Dockerfile.push(`LABEL coolify.image=true`);
	if (secrets.length > 0) {
		secrets.forEach((secret) => {
			if (secret.isBuildSecret) {
				if (pullmergeRequestId) {
					if (secret.isPRMRSecret) {
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
	if (depsFound) {
		Dockerfile.push(`COPY .${baseDirectory || ''}/deps.ts /app`);
		Dockerfile.push(`RUN deno cache deps.ts`);
	}
	console.log(denoOptions && denoOptions.split());
	Dockerfile.push(`COPY ${denoMainFile} /app`);
	Dockerfile.push(`RUN deno cache ${denoMainFile}`);
	Dockerfile.push(`COPY .${baseDirectory || ''} ./`);
	Dockerfile.push(`ENV NO_COLOR true`);
	Dockerfile.push(`EXPOSE ${port}`);
	Dockerfile.push(`CMD deno run ${denoOptions ? denoOptions.split(' ') : ''} ${denoMainFile}`);
	await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile.join('\n'));
};

export default async function (data) {
	try {
		const image = 'denoland/deno:latest';
		await createDockerfile(data, image);
		await buildImage(data);
	} catch (error) {
		throw error;
	}
}
