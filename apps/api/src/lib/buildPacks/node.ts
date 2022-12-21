import { promises as fs } from 'fs';
import { generateSecrets } from '../common';
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
		generateSecrets(secrets, pullmergeRequestId, true).forEach((env) => {
			Dockerfile.push(env);
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
