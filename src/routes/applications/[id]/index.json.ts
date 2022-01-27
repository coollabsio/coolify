import { getTeam, getUserDetails } from '$lib/common';
import { getGithubToken } from '$lib/components/common';
import * as db from '$lib/database';
import { PrismaErrorHandler } from '$lib/database';
import { checkContainer } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';
import jsonwebtoken from 'jsonwebtoken';

export const get: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const appId = process.env['COOLIFY_APP_ID']
	let githubToken = null;
	let ghToken = null;
	let isRunning = false;

	const { id } = event.params;
	try {
		const application = await db.getApplication({ id, teamId });
		const { gitSource } = application;
		if (gitSource?.type === 'github' && gitSource?.githubApp) {
			const payload = {
				iat: Math.round(new Date().getTime() / 1000),
				exp: Math.round(new Date().getTime() / 1000 + 60),
				iss: gitSource.githubApp.appId
			};
			githubToken = jsonwebtoken.sign(payload, gitSource.githubApp.privateKey, {
				algorithm: 'RS256'
			});
			ghToken = await getGithubToken({ apiUrl: gitSource.apiUrl, application, githubToken });
		}
		if (application.destinationDockerId) {
			isRunning = await checkContainer(application.destinationDocker.engine, id);
		}
		return {
			body: {
				isRunning,
				ghToken,
				githubToken,
				application,
				appId
			}
		};
	} catch (error) {
		console.log(error);
		return PrismaErrorHandler(error);
	}
};

export const post: RequestHandler<Locals> = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;
	let {
		name,
		buildPack,
		fqdn,
		port,
		installCommand,
		buildCommand,
		startCommand,
		baseDirectory,
		publishDirectory
	} = await event.request.json();

	if (port) port = Number(port);

	try {
		await db.configureApplication({
			id,
			buildPack,
			name,
			fqdn,
			port,
			installCommand,
			buildCommand,
			startCommand,
			baseDirectory,
			publishDirectory
		});
		return { status: 201 };
	} catch (error) {
		return PrismaErrorHandler(error);
	}
};
