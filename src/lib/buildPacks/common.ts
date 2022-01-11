import { base64Encode } from '$lib/crypto';
import { version } from '$lib/common';
import * as db from '$lib/database';

export function makeLabelForApplication({ applicationId, domain, name, type, pullmergeRequestId = null, buildPack, repository, branch, projectId, port, commit, installCommand, buildCommand, startCommand, baseDirectory, publishDirectory }) {
    return [
        '--label coolify.managed=true',
        `--label coolify.version=${version}`,
        `--label coolify.type=application`,
        `--label coolify.configuration=${base64Encode(JSON.stringify({
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
export async function makeLabelForDatabase({ id, image, volume }) {
    const database = await db.prisma.database.findFirst({ where: { id } })
    delete database.destinationDockerId
    delete database.createdAt
    delete database.updatedAt
    delete database.url
    return [
        'coolify.managed=true',
        `coolify.version=${version}`,
        `coolify.type=database`,
        `coolify.configuration=${base64Encode(JSON.stringify({
            version,
            image,
            volume,
            ...database
        }))}`,
    ]
}