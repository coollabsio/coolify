import { buildImage } from '$lib/docker';
import { promises as fs } from 'fs';
import { checkPnpm } from './common';

const createDockerfile = async (data, image): Promise<void> => {
	const {
		workdir,
		port,
		installCommand,
		buildCommand,
		startCommand,
		baseDirectory,
		secrets,
		pullmergeRequestId
	} = data;
	const Dockerfile: Array<string> = [];
	const isPnpm = checkPnpm(installCommand, buildCommand, startCommand);
	Dockerfile.push(`FROM ${image}`);
	Dockerfile.push('WORKDIR /app');
	Dockerfile.push(`LABEL coolify.image=true`);
	if (secrets.length > 0) {
		secrets.forEach((secret) => {
			if (secret.isBuildSecret) {
				if (pullmergeRequestId) {
					if (secret.isPRMRSecret) {
						Dockerfile.push(`ARG ${secret.name} ${secret.value}`);
					}
				} else {
					if (!secret.isPRMRSecret) {
						Dockerfile.push(`ARG ${secret.name} ${secret.value}`);
					}
				}
			}
		});
	}
	if (isPnpm) {
		Dockerfile.push('RUN curl -f https://get.pnpm.io/v6.16.js | node - add --global pnpm');
		Dockerfile.push('RUN pnpm add -g pnpm');
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
		const image = 'node:lts';
		await createDockerfile(data, image);
		await buildImage(data);
	} catch (error) {
		throw error;
	}
}
