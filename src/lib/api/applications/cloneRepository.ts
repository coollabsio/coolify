import jsonwebtoken from 'jsonwebtoken';
import { execShellAsync } from '../common';

export default async function (configuration) {
	try {
		const { GITHUB_APP_PRIVATE_KEY } = process.env;
		const { workdir, isPreviewDeploymentEnabled, pullRequest } = configuration.general;
		const { organization, name, branch } = configuration.repository;
		const github = configuration.github;
		if (!github.installation.id || !github.app.id) {
			throw new Error('Github installation ID is invalid.');
		}
		const githubPrivateKey = GITHUB_APP_PRIVATE_KEY.replace(/\\n/g, '\n').replace(/"/g, '');

		const payload = {
			iat: Math.round(new Date().getTime() / 1000),
			exp: Math.round(new Date().getTime() / 1000 + 60),
			iss: parseInt(github.app.id)
		};

		const jwtToken = jsonwebtoken.sign(payload, githubPrivateKey, {
			algorithm: 'RS256'
		});

		const { token } = await (
			await fetch(
				`https://api.github.com/app/installations/${github.installation.id}/access_tokens`,
				{
					method: 'POST',
					headers: {
						Authorization: 'Bearer ' + jwtToken,
						Accept: 'application/vnd.github.machine-man-preview+json'
					}
				}
			)
		).json();
		await execShellAsync(
			`mkdir -p ${workdir} && git clone -q -b ${branch} https://x-access-token:${token}@github.com/${organization}/${name}.git ${workdir}/`
		);

		if (isPreviewDeploymentEnabled && pullRequest && pullRequest !== 0) {
			await execShellAsync(`cd ${workdir} && git pull origin pull/${pullRequest}/head`)
		}
		configuration.build.container.tag = (
			await execShellAsync(`cd ${workdir}/ && git rev-parse HEAD`)
		)
			.replace('\n', '')
			.slice(0, 7);
	} catch (error) {
		console.log(error);
	}
}
