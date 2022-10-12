import { promises as fs } from 'fs';
import { defaultComposeConfiguration, executeDockerCmd } from '../common';
import { buildImage, saveBuildLog } from './common';
import yaml from 'js-yaml';

export default async function (data) {
    let {
        applicationId,
        debug,
        buildId,
        dockerId,
        network,
        volumes,
        labels,
        workdir,
        baseDirectory,
        secrets,
        pullmergeRequestId,
        port,
        dockerComposeConfiguration
    } = data
    const fileYml = `${workdir}${baseDirectory}/docker-compose.yml`;
    const fileYaml = `${workdir}${baseDirectory}/docker-compose.yaml`;
    let dockerComposeRaw = null;
    let isYml = false;
    try {
        dockerComposeRaw = await fs.readFile(`${fileYml}`, 'utf8')
        isYml = true
    } catch (error) { }
    try {
        dockerComposeRaw = await fs.readFile(`${fileYaml}`, 'utf8')
    } catch (error) { }

    if (!dockerComposeRaw) {
        throw ('docker-compose.yml or docker-compose.yaml are not found!');
    }
    const dockerComposeYaml = yaml.load(dockerComposeRaw)
    if (!dockerComposeYaml.services) {
        throw 'No Services found in docker-compose file.'
    }
    const envs = [
        `PORT=${port}`
    ];
    if (secrets.length > 0) {
        secrets.forEach((secret) => {
            if (pullmergeRequestId) {
                const isSecretFound = secrets.filter(s => s.name === secret.name && s.isPRMRSecret)
                if (isSecretFound.length > 0) {
                    envs.push(`${secret.name}=${isSecretFound[0].value}`);
                } else {
                    envs.push(`${secret.name}=${secret.value}`);
                }
            } else {
                if (!secret.isPRMRSecret) {
                    envs.push(`${secret.name}=${secret.value}`);
                }
            }
        });
    }
    await fs.writeFile(`${workdir}/.env`, envs.join('\n'));
    let envFound = false;
    try {
        envFound = !!(await fs.stat(`${workdir}/.env`));
    } catch (error) {
        //
    }
    const composeVolumes = volumes.map((volume) => {
        return {
            [`${volume.split(':')[0]}`]: {
                name: volume.split(':')[0]
            }
        };
    });
    let networks = {}
    for (let [key, value] of Object.entries(dockerComposeYaml.services)) {
        value['container_name'] = `${applicationId}-${key}`
        value['env_file'] = envFound ? [`${workdir}/.env`] : []
        value['labels'] = labels
        value['volumes'] = volumes
        if (dockerComposeConfiguration[key].port) {
            value['expose'] = [dockerComposeConfiguration[key].port]
        }
        if (value['networks']?.length > 0) {
            value['networks'].forEach((network) => {
                networks[network] = {
                    name: network
                }
            })
        }
        value['networks'] = [...value['networks'] || '', network]
        dockerComposeYaml.services[key] = { ...dockerComposeYaml.services[key], restart: defaultComposeConfiguration(network).restart, deploy: defaultComposeConfiguration(network).deploy }
    }
    dockerComposeYaml['volumes'] = Object.assign({ ...dockerComposeYaml['volumes'] }, ...composeVolumes)
    dockerComposeYaml['networks'] = Object.assign({ ...networks }, { [network]: { external: true } })
    await fs.writeFile(`${workdir}/docker-compose.${isYml ? 'yml' : 'yaml'}`, yaml.dump(dockerComposeYaml));
    await executeDockerCmd({ debug, buildId, applicationId, dockerId, command: `docker compose --project-directory ${workdir} pull` })
    await saveBuildLog({ line: 'Pulling images from Compose file.', buildId, applicationId });
    await executeDockerCmd({ debug, buildId, applicationId, dockerId, command: `docker compose --project-directory ${workdir} build --progress plain` })
    await saveBuildLog({ line: 'Building images from Compose file.', buildId, applicationId });
}
