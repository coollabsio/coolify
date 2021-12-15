import { buildCacheImageWithNode, buildImage } from '$lib/docker';
import { promises as fs } from 'fs';
import { makeLabel } from './common';

const createDockerfile = async ({ applicationId, commit, image, workdir, buildCommand, baseDirectory, publishDirectory, label, secrets }): Promise<void> => {
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
        Dockerfile.push(`COPY --from=${applicationId}:${commit.slice(0, 7)}-cache /usr/src/app/${publishDirectory} ./`)
    } else {
        Dockerfile.push(`COPY ./${baseDirectory || ""} ./`)
    }
    Dockerfile.push(`EXPOSE 80`)
    Dockerfile.push('CMD ["nginx", "-g", "daemon off;"]')
    await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile.join('\n'))
}

export default async function ({ applicationId, debugLogs, commit, workdir, docker, buildId, installCommand, buildCommand, baseDirectory, publishDirectory, secrets, job }) {
    const image = 'nginx:stable-alpine'
    const label = makeLabel(job)
    if (buildCommand) {
        await buildCacheImageWithNode({ applicationId, commit, workdir, docker, buildId, baseDirectory, installCommand, buildCommand, debugLogs, secrets })
    }
    await createDockerfile({ applicationId, commit, image, workdir, buildCommand, baseDirectory, publishDirectory, label, secrets })
    await buildImage({ applicationId, commit, workdir, docker, buildId, debugLogs })
}