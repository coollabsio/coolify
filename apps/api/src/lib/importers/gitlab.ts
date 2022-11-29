import { saveBuildLog } from "../buildPacks/common";
import { asyncExecShell } from "../common";

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
	forPublic
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
}): Promise<string> {
	const url = htmlUrl.replace('https://', '').replace('http://', '').replace(/\/$/, '');
	await saveBuildLog({ line: 'GitLab importer started.', buildId, applicationId });

	if (!forPublic) {
		await asyncExecShell(`echo '${privateSshKey}' > ${repodir}/id.rsa`);
		await asyncExecShell(`chmod 600 ${repodir}/id.rsa`);
	}

	await saveBuildLog({
		line: `Cloning ${repository}:${branch} branch.`,
		buildId,
		applicationId
	});

	if (forPublic) {
		await asyncExecShell(
			`git clone -q -b ${branch} https://${url}/${repository}.git ${workdir}/ && cd ${workdir}/ && git checkout ${gitCommitHash || ""} && git submodule update --init --recursive && git lfs pull && cd .. `
		);
	} else {
		await asyncExecShell(
			`git clone -q -b ${branch} git@${url}:${repository}.git --config core.sshCommand="ssh -p ${customPort} -q -i ${repodir}id.rsa -o StrictHostKeyChecking=no" ${workdir}/ && cd ${workdir}/ && git checkout ${gitCommitHash || ""} && git submodule update --init --recursive && git lfs pull && cd .. `
		);
	}
	
	const { stdout: commit } = await asyncExecShell(`cd ${workdir}/ && git rev-parse HEAD`);
	return commit.replace('\n', '');
}
