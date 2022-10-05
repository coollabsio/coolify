import { promises as fs } from 'fs';
import { executeDockerCmd } from '../common';
import { buildImage } from './common';
import yaml from 'js-yaml';

export default async function (data) {
	let {
		applicationId,
        dockerId,
		debug,
		tag,
		workdir,
		buildId,
		baseDirectory,
		secrets,
		pullmergeRequestId,
		dockerFileLocation
	} = data
    const file = `${workdir}${baseDirectory}/docker-compose.yml`;
    const dockerComposeRaw = await fs.readFile(`${file}`, 'utf8')
    const dockerComposeYaml = yaml.load(dockerComposeRaw)
    if (!dockerComposeYaml.services) {
        throw 'No Services found in docker-compose file.'
    }
    for (let [key, value] of Object.entries(dockerComposeYaml.services)) {
        value['container_name'] = `${applicationId}-${key}`
        console.log({key, value});
    }

    throw 'Halting'
    // await executeDockerCmd({ debug, buildId, applicationId, dockerId, command: `docker compose --project-directory ${workdir} pull` })
    // await executeDockerCmd({ debug, buildId, applicationId, dockerId, command: `docker compose --project-directory ${workdir} build --progress plain --pull` })
    // await executeDockerCmd({ debug, buildId, applicationId, dockerId, command: `docker compose --project-directory ${workdir} up -d` })
}
