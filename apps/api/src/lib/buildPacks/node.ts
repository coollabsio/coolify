import { promises as fs } from 'fs';
import { buildImage, checkPnpm } from './common';

const createDockerfile = async (data, image): Promise<void> => {
	const {
		workdir,
		port,
		installCommand,
		buildCommand,
		startCommand,
		baseDirectory,
		secrets,
		pullmergeRequestId,
		buildId
	} = data;
	const Dockerfile: Array<string> = [];
	const isPnpm = checkPnpm(installCommand, buildCommand, startCommand);

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
	if (isPnpm) {
		Dockerfile.push('RUN curl -f https://get.pnpm.io/v6.16.js | node - add --global pnpm@7');
	}
	Dockerfile.push(`COPY .${baseDirectory || ''} ./`);
	Dockerfile.push(`RUN ${installCommand}`);
	if (buildCommand) {
		Dockerfile.push(`RUN ${buildCommand}`);
	}
	Dockerfile.push(`EXPOSE ${port}`);
	Dockerfile.push(`CMD ${startCommand}`);
	await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile.join('\n'));
};

export default async function (data) {
	try {
		const { baseImage } = data;
		await createDockerfile(data, baseImage);
		await buildImage(data);
	} catch (error) {
		throw error;
	}
}
