import { prisma } from '$lib/database';
import { buildQueue } from '.';
import got from 'got';
import { asyncExecShell, version } from '$lib/common';
import compare from 'compare-versions';
import { dev } from '$app/env';

export default async function (): Promise<void> {
	const currentVersion = version;
	const { isAutoUpdateEnabled } = await prisma.setting.findFirst();
	if (isAutoUpdateEnabled) {
		const versions = await got
			.get(
				`https://get.coollabs.io/versions.json?appId=${process.env['COOLIFY_APP_ID']}&version=${currentVersion}`
			)
			.json();
		const latestVersion = versions['coolify'].main.version;
		const isUpdateAvailable = compare(latestVersion, currentVersion);
		if (isUpdateAvailable === 1) {
			const activeCount = await buildQueue.getActiveCount();
			if (activeCount === 0) {
				if (!dev) {
					console.log('Updating...');
					await asyncExecShell(`docker pull coollabsio/coolify:${latestVersion}`);
					await asyncExecShell(`env | grep COOLIFY > .env`);
					await asyncExecShell(
						`docker run --rm -tid --env-file .env -v /var/run/docker.sock:/var/run/docker.sock -v coolify-db coollabsio/coolify:${latestVersion} /bin/sh -c "env | grep COOLIFY > .env && echo 'TAG=${latestVersion}' >> .env && docker stop -t 0 coolify coolify-redis && docker rm coolify coolify-redis && docker compose up -d --force-recreate"`
					);
				} else {
					console.log('Updating (not really in dev mode).');
				}
			}
		} else {
			console.log('No update available.');
		}
	} else {
		console.log('Auto update is disabled.');
	}
}
