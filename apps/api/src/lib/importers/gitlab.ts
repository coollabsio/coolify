import { saveBuildLog } from "../buildPacks/common";
import { executeCommand } from "../common";

export default async function ({
	applicationId,
	workdir,
	repodir,
	htmlUrl,
	gitCommitHash,
	repository,
	branch,
	buildId,
	privateSshKey,
	customPort,
	forPublic,
	customUser,
}: {
	applicationId: string;
	workdir: string;
	repository: string;
	htmlUrl: string;
	branch: string;
	buildId: string;
	repodir: string;
	gitCommitHash: string;
	privateSshKey: string;
	customPort: number;
	forPublic: boolean;
	customUser: string;
}): Promise<string> {
	const url = htmlUrl.replace('https://', '').replace('http://', '').replace(/\/$/, '');
	if (!forPublic) {
		await executeCommand({ command: `echo '${privateSshKey}' > ${repodir}/id.rsa`, shell: true });
		await executeCommand({ command: `chmod 600 ${repodir}/id.rsa` });
	}

	await saveBuildLog({
		line: `Cloning ${repository}:${branch}...`,
		buildId,
		applicationId
	});
	if (gitCommitHash) {
		await saveBuildLog({
			line: `Checking out ${gitCommitHash} commit...`,
			buildId,
			applicationId
		});
	}
	if (forPublic) {
		await executeCommand({
			command:
				`git clone -q -b ${branch} https://${url}/${repository}.git ${workdir}/ && cd ${workdir}/ && git checkout ${gitCommitHash || ""} && git submodule update --init --recursive && git lfs pull && cd .. `, shell: true
		}
		);
	} else {
		await executeCommand({
			command:
				`git clone -q -b ${branch} ${customUser}@${url}:${repository}.git --config core.sshCommand="ssh -p ${customPort} -q -i ${repodir}id.rsa -o StrictHostKeyChecking=no" ${workdir}/ && cd ${workdir}/ && git checkout ${gitCommitHash || ""} && git submodule update --init --recursive && git lfs pull && cd .. `, shell: true
		}
		);
	}

	const { stdout: commit } = await executeCommand({ command: `cd ${workdir}/ && git rev-parse HEAD`, shell: true });
	return commit.replace('\n', '');
}
