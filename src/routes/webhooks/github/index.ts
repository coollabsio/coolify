import { dev } from '$app/env';
import { getTeam } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const options = async () => {
	return {
		status: 200,
		headers: {
			'Access-Control-Allow-Origin': '*',
			'Access-Control-Allow-Headers': 'Content-Type, Authorization',
			'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS'
		}
	};
};

export const get: RequestHandler = async (request) => {
	const teamId = undefined;
	const code = request.url.searchParams.get('code');
	const state = request.url.searchParams.get('state');
	try {
		const { apiUrl } = await db.getSource({ id: state, teamId });
		const response = await fetch(`${apiUrl}/app-manifests/${code}/conversions`, { method: 'POST' });
		if (!response.ok) {
			const error = await response.json();
			return {
				status: 500,
				body: { ...error }
			};
		}
		const { id, client_id, slug, client_secret, pem, webhook_secret } = await response.json();
		await db.createGithubApp({ id, client_id, slug, client_secret, pem, webhook_secret, state });
		return {
			status: 302,
			headers: { Location: `/webhooks/success` }
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
