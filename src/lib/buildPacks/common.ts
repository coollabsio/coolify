import { encrypt, base64Encode } from '$lib/crypto';
import { version } from '$lib/common';
export function makeLabel({ applicationId, domain, name, type, pullmergeRequestId = null, buildPack, repository, branch, projectId, port, commit, installCommand, buildCommand, startCommand, baseDirectory, publishDirectory }) {
    return [
        'LABEL coolify.managed=true',
        `LABEL coolify.configuration=${base64Encode(JSON.stringify({
            version,
            applicationId,
            domain,
            name,
            type,
            pullmergeRequestId,
            buildPack,
            repository,
            branch,
            projectId,
            port,
            commit,
            installCommand,
            buildCommand,
            startCommand,
            baseDirectory,
            publishDirectory
        }))}`,
    ]
}