import { asyncExecShell, saveBuildLog } from "$lib/common";
import { PrismaErrorHandler } from "$lib/database";

export default async function ({ applicationId, debug, workdir, repodir, repository, branch, buildId, privateSshKey }): Promise<any> {
    try {
        saveBuildLog({ line: 'GitLab importer started.', buildId, applicationId })
        await asyncExecShell(`echo '${privateSshKey}' > ${repodir}/id.rsa`)
        await asyncExecShell(`chmod 600 ${repodir}/id.rsa`)
        
        saveBuildLog({ line: `Cloning ${repository} repository ${branch} branch.`, buildId, applicationId })

        await asyncExecShell(`git clone -q -b ${branch} git@gitlab.com:${repository}.git --config core.sshCommand="ssh -q -i ${repodir}id.rsa -o StrictHostKeyChecking=no" ${workdir}/ && cd ${workdir}/ && git submodule update --init --recursive && cd ..`)
        const { stdout: commit } = await asyncExecShell(`cd ${workdir}/ && git rev-parse HEAD`)
        return commit.replace('\n', '')
    } catch (error) {
        return PrismaErrorHandler(error)
    }

}