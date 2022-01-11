import { buildImage } from '$lib/docker';
import { promises as fs } from 'fs';
import { makeLabelForApplication } from './common';

export default async function ({ applicationId, domain, name, type, pullmergeRequestId, buildPack, repository, branch, projectId, publishDirectory, debug, commit, tag, workdir, docker, buildId, port, installCommand, buildCommand, startCommand, baseDirectory, secrets }) {
    try {
        const label = makeLabelForApplication({ applicationId, domain, name, type, pullmergeRequestId, buildPack, repository, branch, projectId, port, commit, installCommand, buildCommand, startCommand, baseDirectory, publishDirectory })

        let file = `${workdir}/Dockerfile`
        if (baseDirectory) {
            file = `${workdir}/${baseDirectory}/Dockerfile`
        }

        const Dockerfile: Array<string> = (await fs.readFile(`${file}`, 'utf8')).toString().trim().split('\n')
        if (secrets.length > 0) {
            secrets.forEach(secret => {
                if (secret.isBuildSecret) {
                    Dockerfile.push(`ARG ${secret.name} ${secret.value}`)
                }
            })
        }
        label.forEach(l => Dockerfile.push(l))
        
        await fs.writeFile(`${file}`, Dockerfile.join('\n'))
        
        await buildImage({ applicationId, tag, workdir, docker, buildId, debug })
    } catch (error) {
        throw error
    }
}