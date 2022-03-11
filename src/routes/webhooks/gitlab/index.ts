import { dev } from '$app/env';
import { getTeam } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import got from 'got';
import cookie from 'cookie';

export const options: RequestHandler = async () => {
	return {
		status: 204,
		headers: {
			'Access-Control-Allow-Origin': '*',
			'Access-Control-Allow-Headers': 'Content-Type, Authorization',
			'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS'
		}
	};
};

export const get: RequestHandler = async (event) => {
	const teamId = undefined;
	const code = event.url.searchParams.get('code');
	const state = event.url.searchParams.get('state');
	try {
		const { fqdn } = await db.listSettings();
		const application = await db.getApplication({ id: state, teamId });
		const { appId, appSecret } = application.gitSource.gitlabApp;
		const { htmlUrl } = application.gitSource;

		let domain = `http://${event.url.host}`;
		if (fqdn) domain = fqdn;

		const { access_token } = await got
			.post(`${htmlUrl}/oauth/token`, {
				searchParams: {
					client_id: appId,
					client_secret: appSecret,
					code,
					state,
					grant_type: 'authorization_code',
					redirect_uri: `${domain}/webhooks/gitlab`
				}
			})
			.json();

		return {
			status: 302,
			headers: {
				Location: `/webhooks/success`,
				'Set-Cookie': [`gitlabToken=${access_token}; HttpOnly; Path=/; Max-Age=15778800;`]
			}
		};
	} catch (err) {
		console.log(err);
		return {
			status: 500,
			body: err.message
		};
	}
};
