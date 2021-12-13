import { asyncExecShell, saveBuildLog } from "$lib/common";

export default async function ({ applicationId, debugLogs, workdir, repodir, repository, branch, buildId, privateSshKey }): Promise<string> {
    try {
        saveBuildLog({ line: '[GIT IMPORTER] - GitLab importer started.', buildId, applicationId })
        await asyncExecShell(`echo '${privateSshKey}' > ${repodir}/id.rsa`)
        await asyncExecShell(`chmod 600 ${repodir}/id.rsa`)
        await asyncExecShell(`git clone -q -b ${branch} git@gitlab.com:${repository}.git --config core.sshCommand="ssh -q -i ${repodir}id.rsa -o StrictHostKeyChecking=no" ${workdir}/ && cd ${workdir}/ && git submodule update --init --recursive && cd ..`)
        saveBuildLog({ line: '[GIT IMPORTER] - Cloning repository.', buildId, applicationId })
        const { stdout: commit } = await asyncExecShell(`cd ${workdir}/ && git rev-parse HEAD`)
        return commit.replace('\n', '')
    } catch (error) {
        throw new Error(error)
    }

}