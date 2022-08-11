import { executeDockerCmd, prisma } from "../common"

export default async function (data: any): Promise<void> {
    //   console.log(data)
    const {applicationId, tag, dockerId} = data
// 	try {
      await executeDockerCmd({
          dockerId,
          command: `pack build ${applicationId}:${tag} --builder heroku/buildpacks:20`
      })

// 	} catch (error) {
// 		throw error;
// 	}
}
