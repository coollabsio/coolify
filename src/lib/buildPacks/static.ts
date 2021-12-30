import { buildCacheImageWithNode, buildImage } from '$lib/docker';
import { promises as fs } from 'fs';
import { makeLabel } from './common';

const createDockerfile = async ({ applicationId, tag, image, workdir, buildCommand, baseDirectory, publishDirectory, label, secrets }): Promise<void> => {
    let Dockerfile: Array<string> = []
    Dockerfile.push(`FROM ${image}`)
    Dockerfile.push('WORKDIR /usr/share/nginx/html')
    if (secrets.length > 0) {
        secrets.forEach(secret => {
            if (secret.isBuildSecret) {
                Dockerfile.push(`ARG ${secret.name} ${secret.value}`)
            }
        })
    }
    label.forEach(l => Dockerfile.push(l))
    if (buildCommand) {
        Dockerfile.push(`COPY --from=${applicationId}:${tag}-cache /usr/src/app/${publishDirectory} ./`)
    } else {
        Dockerfile.push(`COPY ./${baseDirectory || ""} ./`)
    }
    Dockerfile.push(`EXPOSE 80`)
    Dockerfile.push('CMD ["nginx", "-g", "daemon off;"]')
    await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile.join('\n'))
}

export default async function ({ applicationId, domain, name, type, pullmergeRequestId, buildPack, repository, branch, projectId, publishDirectory, debug, commit, tag, workdir, docker, buildId, port, installCommand, buildCommand, startCommand, baseDirectory, secrets }) {
    console.log(debug)
    const image = 'nginx:stable-alpine'
    const label = makeLabel({ applicationId, domain, name, type, pullmergeRequestId, buildPack, repository, branch, projectId, port, commit, installCommand, buildCommand, startCommand, baseDirectory, publishDirectory })

    if (buildCommand) {
        await buildCacheImageWithNode({ applicationId, tag, workdir, docker, buildId, baseDirectory, installCommand, buildCommand, debug, secrets })
    }
    await createDockerfile({ applicationId, tag, image, workdir, buildCommand, baseDirectory, publishDirectory, label, secrets })
    await buildImage({ applicationId, tag, workdir, docker, buildId, debug })
}