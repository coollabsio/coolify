import { buildImage } from '$lib/docker';
import { promises as fs } from 'fs';

const createDockerfile = async (data, image): Promise<void> => {
    const { workdir, port, installCommand, buildCommand, startCommand, baseDirectory, secrets } = data;
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
    Dockerfile.push(`COPY ./${baseDirectory || ""}package*.json ./`)
    Dockerfile.push(`RUN ${installCommand}`)
    Dockerfile.push(`COPY ./${baseDirectory || ""} ./`)
    if (buildCommand) { Dockerfile.push(`RUN ${buildCommand}`) }
    Dockerfile.push(`EXPOSE ${port}`)
    Dockerfile.push(`CMD ${startCommand}`)
    await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile.join('\n'))
}

export default async function (data) {
    try {
        const image = 'node:lts'
        await createDockerfile(data, image)
        await buildImage(data)
    } catch (error) {
        throw error
    }
}