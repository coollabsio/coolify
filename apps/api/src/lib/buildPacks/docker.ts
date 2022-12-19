import { promises as fs } from 'fs';
import { buildImage } from './common';

export default async function (data) {
	let {
		applicationId,
		debug,
		tag,
		workdir,
		buildId,
		baseDirectory,
		secrets,
		pullmergeRequestId,
		dockerFileLocation
	} = data
	const file = `${workdir}${baseDirectory}${dockerFileLocation}`;
	data.workdir = `${workdir}${baseDirectory}`;
	const DockerfileRaw = await fs.readFile(`${file}`, 'utf8')
	const Dockerfile: Array<string> = DockerfileRaw
		.toString()
		.trim()
		.split('\n');
	Dockerfile.forEach((line, index) => {
		if (line.startsWith('FROM')) {
			Dockerfile.splice(index + 1, 0, `LABEL coolify.buildId=${buildId}`);
		}
	});
	if (secrets.length > 0) {
		secrets.forEach((secret) => {
			if (secret.isBuildSecret) {
				if (
					(pullmergeRequestId && secret.isPRMRSecret) ||
					(!pullmergeRequestId && !secret.isPRMRSecret)
				) {
					Dockerfile.forEach((line, index) => {
						if (line.startsWith('FROM')) {
							Dockerfile.splice(index + 1, 0, `ARG ${secret.name}='${secret.value}'`);
						}
					});
				}
			}
		});
	}

	await fs.writeFile(`${workdir}${dockerFileLocation}`, Dockerfile.join('\n'));
	await buildImage(data);
}
