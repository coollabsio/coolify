import { promises as fs } from 'fs';
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
		secrets.forEach((secret) => {
			if (secret.isBuildSecret) {
				if (pullmergeRequestId) {
					const isSecretFound = secrets.filter(s => s.name === secret.name && s.isPRMRSecret)
					if (isSecretFound.length > 0) {
                            if (isSecretFound[0].value.includes('\\n')|| isSecretFound[0].value.includes("'")) {
						Dockerfile.push(`ARG ${secret.name}=${isSecretFound[0].value}`);
                            } else {

						Dockerfile.push(`ARG ${secret.name}='${isSecretFound[0].value}'`);
                            }
					} else {
                            if (secret.value.includes('\\n')|| secret.value.includes("'")) {
						Dockerfile.push(`ARG ${secret.name}=${secret.value}`);
                            } else {
						Dockerfile.push(`ARG ${secret.name}='${secret.value}'`);
                            }
					}
				} else {
					if (!secret.isPRMRSecret) {
                            if (secret.value.includes('\\n')|| secret.value.includes("'")) {
						Dockerfile.push(`ARG ${secret.name}=${secret.value}`);
                            } else {
						Dockerfile.push(`ARG ${secret.name}='${secret.value}'`);
                            }
					}
				}
			}
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
