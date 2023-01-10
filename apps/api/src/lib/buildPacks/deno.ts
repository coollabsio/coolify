import { promises as fs } from 'fs';
import { generateSecrets } from '../common';
import { buildImage } from './common';

const createDockerfile = async (data, image): Promise<void> => {
	const {
		workdir,
		port,
		baseDirectory,
		secrets,
		pullmergeRequestId,
		denoMainFile,
		denoOptions,
		buildId
	} = data;
	const Dockerfile: Array<string> = [];

	let depsFound = false;
	try {
		await fs.readFile(`${workdir}${baseDirectory || ''}/deps.ts`);
		depsFound = true;
	} catch (error) {}

	Dockerfile.push(`FROM ${image}`);
	Dockerfile.push('WORKDIR /app');
	Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
	if (secrets.length > 0) {
		generateSecrets(secrets, pullmergeRequestId, true).forEach((env) => {
			Dockerfile.push(env);
		});
	}
	if (depsFound) {
		Dockerfile.push(`COPY .${baseDirectory || ''}/deps.ts /app`);
		Dockerfile.push(`RUN deno cache deps.ts`);
	}
	Dockerfile.push(`COPY .${baseDirectory || ''} ./`);
	Dockerfile.push(`RUN deno cache ${denoMainFile}`);
	Dockerfile.push(`ENV NO_COLOR true`);
	Dockerfile.push(`EXPOSE ${port}`);
	Dockerfile.push(`CMD deno run ${denoOptions || ''} ${denoMainFile}`);
	await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile.join('\n'));
};

export default async function (data) {
	try {
		const { baseImage, baseBuildImage } = data;
		await createDockerfile(data, baseImage);
		await buildImage(data);
	} catch (error) {
		throw error;
	}
}
