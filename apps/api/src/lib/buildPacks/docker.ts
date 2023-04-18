import { promises as fs } from 'fs';
import { generateSecrets } from '../common';
import { buildImage } from './common';

export default async function (data) {
	let { workdir, buildId, baseDirectory, secrets, pullmergeRequestId, dockerFileLocation } = data;
	const file = `${workdir}${baseDirectory}${dockerFileLocation}`;
	data.workdir = `${workdir}${baseDirectory}`;
	const DockerfileRaw = await fs.readFile(`${file}`, 'utf8');
	const Dockerfile: Array<string> = DockerfileRaw.toString().trim().split('\n');
	Dockerfile.forEach((line, index) => {
		if (line.startsWith('FROM')) {
			Dockerfile.splice(index + 1, 0, `LABEL coolify.buildId=${buildId}`);
		}
	});
	if (secrets.length > 0) {
		generateSecrets(secrets, pullmergeRequestId, true).forEach((env) => {
			Dockerfile.forEach((line, index) => {
				if (line.startsWith('FROM')) {
					Dockerfile.splice(index + 1, 0, env);
				}
			});
		});
	}
	await fs.writeFile(`${data.workdir}${dockerFileLocation}`, Dockerfile.join('\n'));
	await buildImage(data);
}
