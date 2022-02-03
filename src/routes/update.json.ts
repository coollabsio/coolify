import { dev } from '$app/env';
import { asyncExecShell, version } from '$lib/common';
import { asyncSleep } from '$lib/components/common';
import { PrismaErrorHandler } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import compare from 'compare-versions';
import got from 'got';

export const get: RequestHandler = async () => {
	try {
		const currentVersion = version;
		const versions = await got
			.get(`https://get.coollabs.io/version.json?appId=${process.env['COOLIFY_APP_ID']}`)
			.json();
		const latestVersion = versions['coolify-v2'].main.version;
		const isUpdateAvailable = compare(latestVersion, currentVersion);
		// if (isUpdateAvailable === 1) {
		//     await asyncExecShell(`env | grep COOLIFY > .env`)
		//     asyncExecShell(`docker compose pull`);
		// }
		return {
			body: {
				isUpdateAvailable: isUpdateAvailable === 1,
				latestVersion
			}
		};
	} catch (error) {
		return PrismaErrorHandler(error);
	}
};

export const post: RequestHandler<Locals> = async (event) => {
	const { type } = await event.request.json();
	if (type === 'pull') {
		try {
			if (!dev) {
				await asyncExecShell(`env | grep COOLIFY > .env`);
				await asyncExecShell(`docker compose pull`);
				return {
					status: 200,
					body: {}
				};
			} else {
				await asyncSleep(2000);
				return {
					status: 200,
					body: {}
				};
			}
		} catch (error) {
			return PrismaErrorHandler(error);
		}
	} else if (type === 'update') {
		try {
			if (!dev) {
				await asyncExecShell(
					`docker run --rm -tid --env-file .env -v /var/run/docker.sock:/var/run/docker.sock -v coolify-db coollabsio/coolify:latest /bin/sh -c "env | grep COOLIFY > .env && docker stop -t 0 coolify && docker stop -t 0 coolify-redis && docker compose up -d --force-recreate"`
				);
				return {
					status: 200,
					body: {}
				};
			} else {
				await asyncSleep(2000);
				return {
					status: 200,
					body: {}
				};
			}
		} catch (error) {
			return PrismaErrorHandler(error);
		}
	}
	return {
		status: 500
	};
};
