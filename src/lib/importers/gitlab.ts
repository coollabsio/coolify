import { asyncExecShell, saveBuildLog } from "$lib/common";
import got from "got"
import jsonwebtoken from 'jsonwebtoken'
import * as db from '$lib/database'

export default async function ({ applicationId, workdir, githubAppId, repository, branch, buildId, apiUrl, projectId, deployKeyId, privateSshKey }): Promise<string> {
    try {
        saveBuildLog({ line: 'Importer started.', buildId, applicationId })
        console.log({applicationId, workdir, githubAppId, repository, branch, buildId, apiUrl, projectId, deployKeyId, privateSshKey})
        // TODO: Not working
        await asyncExecShell(`git clone -q -b ${branch} git@gitlab.com:${repository}.git --config core.sshCommand="echo '${privateSshKey}'| grep -qw "less" | ssh -q -i /dev/stdin -o StrictHostKeyChecking=no" ${workdir}/ && cd ${workdir} && git submodule update --init --recursive && cd ..`)
      return 'OK'
    } catch (error) {
        throw new Error(error)
    }

}