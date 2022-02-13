import child from 'child_process';
import util from 'util';
import { dev } from '$app/env';
import * as Sentry from '@sentry/node';
import { uniqueNamesGenerator, Config, adjectives, colors, animals } from 'unique-names-generator';

import * as db from '$lib/database';
import { buildLogQueue } from './queues';

import { version as currentVersion } from '../../package.json';
import { dockerInstance } from './docker';
import dayjs from 'dayjs';
import Cookie from 'cookie';

try {
	if (!dev) {
		Sentry.init({
			dsn: process.env['COOLIFY_SENTRY_DSN'],
			tracesSampleRate: 0,
			environment: 'production'
		});
	}
} catch (err) {
	console.log('Could not initialize Sentry, no worries.');
}

const customConfig: Config = {
	dictionaries: [adjectives, colors, animals],
	style: 'capital',
	separator: ' ',
	length: 3
};

export const version = currentVersion;
export const asyncExecShell = util.promisify(child.exec);
export const asyncSleep = (delay) => new Promise((resolve) => setTimeout(resolve, delay));
export const sentry = Sentry;

export const uniqueName = () => uniqueNamesGenerator(customConfig);

export const saveBuildLog = async ({ line, buildId, applicationId }) => {
	const addTimestamp = `${generateTimestamp()} ${line}`;
	return await buildLogQueue.add(buildId, { buildId, line: addTimestamp, applicationId });
};

export const isTeamIdTokenAvailable = (request) => {
	const cookie = request.headers.cookie
		?.split(';')
		.map((s) => s.trim())
		.find((s) => s.startsWith('teamId='))
		?.split('=')[1];
	if (!cookie) {
		return getTeam(request);
	} else {
		return cookie;
	}
};

export const getTeam = (event) => {
	const cookies: Cookies = Cookie.parse(event.request.headers.get('cookie'));
	if (cookies.teamId) {
		return cookies.teamId;
	} else if (event.locals.session.data.teamId) {
		return event.locals.session.data.teamId;
	}
	return null;
};

export const getUserDetails = async (event, isAdminRequired = true) => {
	const teamId = getTeam(event);
	const userId = event.locals.session.data.uid || null;
	const { permission = 'read' } = await db.prisma.permission.findFirst({
		where: { teamId, userId },
		select: { permission: true },
		rejectOnNotFound: true
	});
	const payload = {
		teamId,
		userId,
		permission,
		status: 200,
		body: {
			message: 'OK'
		}
	};
	if (isAdminRequired && permission !== 'admin' && permission !== 'owner') {
		payload.status = 401;
		payload.body.message =
			'You do not have permission to do this. \nAsk an admin to modify your permissions.';
	}

	return payload;
};

export function getEngine(engine) {
	return engine === '/var/run/docker.sock' ? 'unix:///var/run/docker.sock' : `tcp://${engine}:2375`;
}

export async function removeContainer(id, engine) {
	const host = getEngine(engine);
	try {
		const { stdout } = await asyncExecShell(
			`DOCKER_HOST=${host} docker inspect --format '{{json .State}}' ${id}`
		);
		if (JSON.parse(stdout).Running) {
			await asyncExecShell(`DOCKER_HOST=${host} docker stop -t 0 ${id}`);
			await asyncExecShell(`DOCKER_HOST=${host} docker rm ${id}`);
		}
	} catch (error) {
		console.log(error);
		throw error;
	}
}

export const removeDestinationDocker = async ({ id, engine }) => {
	return await removeContainer(id, engine);
};

export const createDirectories = async ({ repository, buildId }) => {
	const repodir = `/tmp/build-sources/${repository}/`;
	const workdir = `/tmp/build-sources/${repository}/${buildId}`;

	await asyncExecShell(`mkdir -p ${workdir}`);

	return {
		workdir,
		repodir
	};
};

export function generateTimestamp() {
	return `${dayjs().format('HH:mm:ss.SSS')} `;
}

export function getDomain(domain) {
	return domain?.replace('https://', '').replace('http://', '');
}
