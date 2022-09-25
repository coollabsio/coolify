import { executeDockerCmd, prisma } from "../common"
import { saveBuildLog } from "./common";

export default async function (data: any): Promise<void> {
    const { buildId, applicationId, tag, dockerId, debug, workdir, baseDirectory } = data
    try {
        await saveBuildLog({ line: `Building image started.`, buildId, applicationId });
        await executeDockerCmd({
            debug,
            dockerId,
            command: `pack build -p ${workdir}${baseDirectory} ${applicationId}:${tag} --builder heroku/buildpacks:20`
        })
        await saveBuildLog({ line: `Building image successful.`, buildId, applicationId });
    } catch (error) {
        throw error;
    }
}
