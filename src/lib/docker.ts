import Dockerode from 'dockerode'
import { promises as fs } from 'fs';
import { saveBuildLog } from './common';

export async function buildCacheImageWithNode({ applicationId, commit, workdir, docker, buildId, baseDirectory, installCommand, buildCommand }) {
    const Dockerfile: Array<string> = []
    Dockerfile.push(`FROM node:lts`)
    Dockerfile.push('WORKDIR /usr/src/app')
    // TODO: If build command defined, install command should be the default yarn install
    if (installCommand) {
        Dockerfile.push(`COPY ./${baseDirectory || ""}package*.json ./`)
        Dockerfile.push(`RUN ${installCommand}`)
    }
    Dockerfile.push(`COPY ./${baseDirectory || ""} ./`)
    Dockerfile.push(`RUN ${buildCommand}`)
    await fs.writeFile(`${workdir}/Dockerfile-cache`, Dockerfile.join('\n'))
    await buildImage({ applicationId, commit, workdir, docker, buildId, isCache: true })
}

export async function buildImage({ applicationId, commit, workdir, docker, buildId, isCache = false, debugLogs = false }) {
    if (!debugLogs) {
        saveBuildLog({ line: `[COOLIFY] - Debug turned off.`, buildId, applicationId })
    }
    saveBuildLog({ line: `[COOLIFY] - Building image.`, buildId, applicationId })

    const stream = await docker.engine.buildImage(
        { src: ['.'], context: workdir },
        { dockerfile: isCache ? 'Dockerfile-cache' : 'Dockerfile', t: `${applicationId}:${commit.slice(0, 7)}${isCache ? '-cache' : ''}` }
    );
    await streamEvents({ stream, docker, buildId, applicationId, debugLogs })
}

export function dockerInstance({ destinationDocker }): { engine: Dockerode, network: string } {
    return {
        engine: new Dockerode({
            socketPath: destinationDocker.engine,
        }),
        network: destinationDocker.network,
    }
}
export async function streamEvents({ stream, docker, buildId, applicationId, debugLogs }) {
    await new Promise((resolve, reject) => {
        docker.engine.modem.followProgress(stream, onFinished, onProgress);
        function onFinished(err, res) {
            if (err) reject(err);
            resolve(res);
        }
        function onProgress(event) {
            if (event.error) {
                reject(event.error);
            } else if (event.stream) {
                if (event.stream !== '\n') {
                    if (debugLogs) saveBuildLog({ line: `[DOCKER ENGINE] - ${event.stream.replace('\n', '')}`, buildId, applicationId })
                }
            }
        }
    });
}


export const baseServiceConfigurationDocker = {
    restart_policy: {
        condition: 'any',
        max_attempts: 6,
    }
};

export const baseServiceConfigurationSwarm = {
    replicas: 1,
    restart_policy: {
        condition: 'any',
        max_attempts: 6,
    },
    update_config: {
        parallelism: 1,
        delay: '10s',
        order: 'start-first',
    },
    rollback_config: {
        parallelism: 1,
        delay: '10s',
        order: 'start-first',
        failure_action: 'rollback',
    },
};