import { executeDockerCmd, prisma } from "../common"
import { saveBuildLog } from "./common";

export default async function (data: any): Promise<void> {
    const { buildId, applicationId, tag, dockerId, debug, workdir } = data
    try {

        await saveBuildLog({ line: `Building image started.`, buildId, applicationId });
        const { stdout } = await executeDockerCmd({
            dockerId,
            command: `pack build -p ${workdir} ${applicationId}:${tag} --builder heroku/buildpacks:20`
        })
        if (debug) {
            const array = stdout.split('\n')
            for (const line of array) {
                if (line !== '\n') {
                    await saveBuildLog({
                        line: `${line.replace('\n', '')}`,
                        buildId,
                        applicationId
                    });
                }
            }
        }
        await saveBuildLog({ line: `Building image successful.`, buildId, applicationId });
    } catch (error) {
        const array = error.stdout.split('\n')
        for (const line of array) {
            if (line !== '\n') {
                await saveBuildLog({
                    line: `${line.replace('\n', '')}`,
                    buildId,
                    applicationId
                });
            }
        }
        throw error;
    }
}
