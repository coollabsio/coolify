import { buildImage } from '$lib/docker';
import { promises as fs } from 'fs';

export default async function ({
	applicationId,
	debug,
	tag,
	workdir,
	docker,
	buildId,
	baseDirectory,
	secrets,
	pullmergeRequestId,
	dockerFileLocation
}) {
	try {
		const file = `${workdir}${dockerFileLocation}`;
		let dockerFileOut = `${workdir}`;
		if (baseDirectory) {
			dockerFileOut = `${workdir}${baseDirectory}`;
			workdir = `${workdir}${baseDirectory}`;
		}
		const Dockerfile: Array<string> = (await fs.readFile(`${file}`, 'utf8'))
			.toString()
			.trim()
			.split('\n');
		Dockerfile.push(`LABEL coolify.buildId=${buildId}`);
		if (secrets.length > 0) {
			secrets.forEach((secret) => {
				if (secret.isBuildSecret) {
					if (
						(pullmergeRequestId && secret.isPRMRSecret) ||
						(!pullmergeRequestId && !secret.isPRMRSecret)
					) {
						Dockerfile.unshift(`ARG ${secret.name}=${secret.value}`);

						Dockerfile.forEach((line, index) => {
							if (line.startsWith('FROM')) {
								Dockerfile.splice(index + 1, 0, `ARG ${secret.name}`);
							}
						});
					}
				}
			});
		}

		await fs.writeFile(`${dockerFileOut}${dockerFileLocation}`, Dockerfile.join('\n'));
		await buildImage({ applicationId, tag, workdir, docker, buildId, debug, dockerFileLocation });
	} catch (error) {
		throw error;
	}
}
