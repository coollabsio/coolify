
import jsonwebtoken from 'jsonwebtoken';
import { saveBuildLog } from '../buildPacks/common';
import { asyncExecShell, decrypt, prisma } from '../common';

export default async function ({
	applicationId,
	workdir,
	githubAppId,
	repository,
	apiUrl,
	htmlUrl,
	branch,
	buildId
}: {
	applicationId: string;
	workdir: string;
	githubAppId: string;
	repository: string;
	apiUrl: string;
	htmlUrl: string;
	branch: string;
	buildId: string;
}): Promise<string> {
	const { default: got } = await import('got')
	const url = htmlUrl.replace('https://', '').replace('http://', '');
	await saveBuildLog({ line: 'GitHub importer started.', buildId, applicationId });

	const body = await prisma.githubApp.findUnique({ where: { id: githubAppId } });
	if (body.privateKey) body.privateKey = decrypt(body.privateKey);
	const { privateKey, appId, installationId } = body

	const githubPrivateKey = privateKey.replace(/\\n/g, '\n').replace(/"/g, '');

	const payload = {
		iat: Math.round(new Date().getTime() / 1000),
		exp: Math.round(new Date().getTime() / 1000 + 60),
		iss: appId
	};
	const jwtToken = jsonwebtoken.sign(payload, githubPrivateKey, {
		algorithm: 'RS256'
	});
	const { token } = await got
		.post(`${apiUrl}/app/installations/${installationId}/access_tokens`, {
			headers: {
				Authorization: `Bearer ${jwtToken}`,
				Accept: 'application/vnd.github.machine-man-preview+json'
			}
		})
		.json();
	await saveBuildLog({
		line: `Cloning ${repository}:${branch} branch.`,
		buildId,
		applicationId
	});
	await asyncExecShell(
		`git clone -q -b ${branch} https://x-access-token:${token}@${url}/${repository}.git ${workdir}/ && cd ${workdir} && git submodule update --init --recursive && git lfs pull && cd .. `
	);
	const { stdout: commit } = await asyncExecShell(`cd ${workdir}/ && git rev-parse HEAD`);
	return commit.replace('\n', '');
}
