
import jsonwebtoken from 'jsonwebtoken';
import { saveBuildLog } from '../buildPacks/common';
import { decrypt, executeCommand, prisma } from '../common';

export default async function ({
	applicationId,
	workdir,
	githubAppId,
	repository,
	apiUrl,
	gitCommitHash,
	htmlUrl,
	branch,
	buildId,
	customPort,
	forPublic
}: {
	applicationId: string;
	workdir: string;
	githubAppId: string;
	repository: string;
	apiUrl: string;
	gitCommitHash?: string;
	htmlUrl: string;
	branch: string;
	buildId: string;
	customPort: number;
	forPublic?: boolean;
}): Promise<string> {
	const { default: got } = await import('got')
	const url = htmlUrl.replace('https://', '').replace('http://', '');
	if (forPublic) {
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
		await executeCommand({
			command:
				`git clone -q -b ${branch} https://${url}/${repository}.git ${workdir}/ && cd ${workdir} && git checkout ${gitCommitHash || ""} && git submodule update --init --recursive && git lfs pull && cd .. `,
			shell: true
		});

	} else {
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
		await executeCommand({
			command:
				`git clone -q -b ${branch} https://x-access-token:${token}@${url}/${repository}.git --config core.sshCommand="ssh -p ${customPort}" ${workdir}/ && cd ${workdir} && git checkout ${gitCommitHash || ""} && git submodule update --init --recursive && git lfs pull && cd .. `,
			shell: true
		});
	}
	const { stdout: commit } = await executeCommand({ command: `cd ${workdir}/ && git rev-parse HEAD`, shell: true });
	return commit.replace('\n', '');
}
