import { buildImage } from '$lib/docker';
import { promises as fs } from 'fs';
import { makeLabel } from './common';

const createDockerfile = async ({ image, workdir, port, installCommand, buildCommand, startCommand, baseDirectory, label, secrets }): Promise<void> => {
    const Dockerfile: Array<string> = []
    Dockerfile.push(`FROM ${image}`)
    Dockerfile.push('WORKDIR /usr/src/app')
    if (secrets.length > 0) {
        secrets.forEach(secret => {
            if (secret.isBuildSecret) {
                Dockerfile.push(`ARG ${secret.name} ${secret.value}`)
            }
        })
    }
    label.forEach(l => Dockerfile.push(l))
    Dockerfile.push(`COPY ./${baseDirectory || ""}package*.json ./`)
    Dockerfile.push(`RUN ${installCommand}`)
    Dockerfile.push(`COPY ./${baseDirectory || ""} ./`)
    if (buildCommand) { Dockerfile.push(`RUN ${buildCommand}`) }
    Dockerfile.push(`EXPOSE ${port}`)
    Dockerfile.push(`CMD ${startCommand}`)
    await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile.join('\n'))
}

export default async function ({ applicationId, imageId, debugLogs, commit, workdir, docker, buildId, port, installCommand, buildCommand, startCommand, baseDirectory, secrets, job }) {
    // TODO: Select node version
    const image = 'node:lts'
    const label = makeLabel(job)
    await createDockerfile({ image, workdir, port, installCommand, buildCommand, startCommand, baseDirectory, label, secrets })
    await buildImage({ applicationId, imageId, commit, workdir, docker, buildId, debugLogs })
}