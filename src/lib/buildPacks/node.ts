import { buildImage } from '$lib/docker';
import { promises as fs } from 'fs';

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
	const isPnpm =
		installCommand.includes('pnpm') ||
		buildCommand.includes('pnpm') ||
		startCommand.includes('pnpm');
	Dockerfile.push(`FROM ${image}`);
	Dockerfile.push('WORKDIR /usr/src/app');
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
	Dockerfile.push(`COPY ./${baseDirectory || ''}package*.json ./`);
	try {
		await fs.stat(`${workdir}/yarn.lock`);
		Dockerfile.push(`COPY ./${baseDirectory || ''}yarn.lock ./`);
	} catch (error) {}
	try {
		await fs.stat(`${workdir}/pnpm-lock.yaml`);
		Dockerfile.push(`COPY ./${baseDirectory || ''}pnpm-lock.yaml ./`);
	} catch (error) {}
	Dockerfile.push(`RUN ${installCommand}`);
	Dockerfile.push(`COPY ./${baseDirectory || ''} ./`);
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
