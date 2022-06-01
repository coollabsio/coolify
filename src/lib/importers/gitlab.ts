import { asyncExecShell, saveBuildLog } from '$lib/common';

export default async function ({
	applicationId,
	workdir,
	repodir,
	htmlUrl,
	repository,
	branch,
	buildId,
	privateSshKey
}: {
	applicationId: string;
	workdir: string;
	repository: string;
	htmlUrl: string;
	branch: string;
	buildId: string;
	repodir: string;
	privateSshKey: string;
}): Promise<string> {
	const url = htmlUrl.replace('https://', '').replace('http://', '').replace(/\/$/, '');
	await saveBuildLog({ line: 'GitLab importer started.', buildId, applicationId });
	await asyncExecShell(`echo '${privateSshKey}' > ${repodir}/id.rsa`);
	await asyncExecShell(`chmod 600 ${repodir}/id.rsa`);

	await saveBuildLog({
		line: `Cloning ${repository}:${branch} branch.`,
		buildId,
		applicationId
	});

	await asyncExecShell(
		`git clone -q -b ${branch} git@${url}:${repository}.git --config core.sshCommand="ssh -q -i ${repodir}id.rsa -o StrictHostKeyChecking=no" ${workdir}/ && cd ${workdir}/ && git submodule update --init --recursive && git lfs pull && cd .. `
	);
	const { stdout: commit } = await asyncExecShell(`cd ${workdir}/ && git rev-parse HEAD`);
	return commit.replace('\n', '');
}
