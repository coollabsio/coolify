import { executeCommand } from "../common"
import { saveBuildLog } from "./common";

export default async function (data: any): Promise<void> {
    const { buildId, applicationId, tag, dockerId, debug, workdir, baseDirectory, baseImage } = data
    try {
        await saveBuildLog({ line: `Building production image...`, buildId, applicationId });
        await executeCommand({
            buildId,
            debug,
            dockerId,
            command: `pack build -p ${workdir}${baseDirectory} ${applicationId}:${tag} --builder ${baseImage}`
        })
    } catch (error) {
        throw error;
    }
}
