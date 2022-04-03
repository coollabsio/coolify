import { asyncExecShell, saveBuildLog } from '$lib/common';
import got from 'got';
import jsonwebtoken from 'jsonwebtoken';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';

export default async function ({
	applicationId,
	debug,
	workdir,
	githubAppId,
	repository,
	branch,
	buildId
}): Promise<any> {
	try {
		await saveBuildLog({ line: 'GitHub importer started.', buildId, applicationId });
		const { privateKey, appId, installationId } = await db.getUniqueGithubApp({ githubAppId });
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
			.post(`https://api.github.com/app/installations/${installationId}/access_tokens`, {
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
			`git clone -q -b ${branch} https://x-access-token:${token}@github.com/${repository}.git ${workdir}/ && cd ${workdir} && git submodule update --init --recursive && cd ..`
		);
		const { stdout: commit } = await asyncExecShell(`cd ${workdir}/ && git rev-parse HEAD`);
		return commit.replace('\n', '');
	} catch (error) {
		console.log({ error });
		return ErrorHandler(error);
	}
}
