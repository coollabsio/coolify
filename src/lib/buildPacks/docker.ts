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
	pullmergeRequestId
}) {
	try {
		let file = `${workdir}/Dockerfile`;
		if (baseDirectory) {
			file = `${workdir}/${baseDirectory}/Dockerfile`;
			workdir = `${workdir}/${baseDirectory}`;
		}

		const Dockerfile: Array<string> = (await fs.readFile(`${file}`, 'utf8'))
			.toString()
			.trim()
			.split('\n');
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
		await fs.writeFile(`${file}`, Dockerfile.join('\n'));
		await buildImage({ applicationId, tag, workdir, docker, buildId, debug });
	} catch (error) {
		throw error;
	}
}
