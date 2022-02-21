import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import jsonwebtoken from 'jsonwebtoken';

export const get: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	try {
		const application = await db.getApplication({ id, teamId });
		const payload = {
			iat: Math.round(new Date().getTime() / 1000),
			exp: Math.round(new Date().getTime() / 1000 + 60),
			iss: application.gitSource.githubApp.appId
		};
		const githubToken = jsonwebtoken.sign(payload, application.gitSource.githubApp.privateKey, {
			algorithm: 'RS256'
		});
		const response = await fetch(
			`${application.gitSource.apiUrl}/app/installations/${application.gitSource.githubApp.installationId}/access_tokens`,
			{
				method: 'POST',
				headers: {
					Authorization: `Bearer ${githubToken}`
				}
			}
		);
		if (!response.ok) {
			throw new Error(`${response.status} ${response.statusText}`);
		}
		const data = await response.json();
		return {
			status: 201,
			body: { token: data.token },
			headers: {
				'Set-Cookie': `githubToken=${data.token}; Path=/; HttpOnly; Max-Age=15778800;`
			}
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
