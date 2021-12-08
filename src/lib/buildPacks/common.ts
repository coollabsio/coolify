import { encrypt, base64Encode } from '$lib/crypto';
export function makeLabel(data) {
    return base64Encode(encrypt(JSON.stringify({
        coolifyManaged: true,
        version: '2.0.0',
        domain: data.domain,
        name: data.name,
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
    })))
}