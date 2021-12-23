import { encrypt, base64Encode } from '$lib/crypto';
import { version } from '$lib/common';
export function makeLabel(data) {
    return [
        'LABEL coolify.managed=true',
        `LABEL coolify.configuration=${base64Encode(JSON.stringify({
            version,
            domain: data.domain,
            name: data.name,
            type: data.type,
            buildpack: data.buildpack,
            repository: data.repository,
            branch: data.branch,
            projectId: data.projectId,
            port: data.port,
            commit: data.commit,
            installCommand: data.installCommand,
            buildCommand: data.buildCommand,
            startCommand: data.startCommand,
            baseDirectory: data.baseDirectory,
            publishDirectory: data.publishDirectory,
        }))}`,
    ]
}