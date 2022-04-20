import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { checkContainer, isContainerExited } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';
import jsonwebtoken from 'jsonwebtoken';
import { get as getRequest } from '$lib/api';
import { setDefaultConfiguration } from '$lib/buildPacks/common';

export const get: RequestHandler = async (event) => {
	const { teamId, status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;

	const appId = process.env['COOLIFY_APP_ID'];
	let isRunning = false;
	let isExited = false;
	let githubToken = event.locals.cookies?.githubToken || null;
	let gitlabToken = event.locals.cookies?.gitlabToken || null;
	try {
		const application = await db.getApplication({ id, teamId });
		if (application.destinationDockerId) {
			isRunning = await checkContainer(application.destinationDocker.engine, id);
			isExited = await isContainerExited(application.destinationDocker.engine, id);
		}
		return {
			status: 200,
			body: {
				isRunning,
				isExited,
				application,
				appId,
				githubToken,
				gitlabToken
			},
			headers: {}
		};
	} catch (error) {
		console.log(error);
		return ErrorHandler(error);
	}
};

export const post: RequestHandler = async (event) => {
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
		publishDirectory,
		pythonWSGI,
		pythonModule,
		pythonVariable,
		dockerFileLocation,
		denoMainFile,
		denoOptions
	} = await event.request.json();
	if (port) port = Number(port);
	if (denoOptions) denoOptions = denoOptions.trim();

	try {
		const defaultConfiguration = await setDefaultConfiguration({
			buildPack,
			port,
			installCommand,
			startCommand,
			buildCommand,
			publishDirectory,
			baseDirectory,
			dockerFileLocation,
			denoMainFile
		});
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
			publishDirectory,
			pythonWSGI,
			pythonModule,
			pythonVariable,
			dockerFileLocation,
			denoMainFile,
			denoOptions,
			...defaultConfiguration
		});
		return { status: 201 };
	} catch (error) {
		return ErrorHandler(error);
	}
};
