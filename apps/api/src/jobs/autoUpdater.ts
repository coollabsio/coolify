import axios from 'axios';
import compareVersions from 'compare-versions';
import { parentPort } from 'node:worker_threads';
import { asyncExecShell, asyncSleep, isDev, prisma, version } from '../lib/common';

(async () => {
    if (parentPort) {
        try {
            const currentVersion = version;
            const { isAutoUpdateEnabled } = await prisma.setting.findFirst();
            if (isAutoUpdateEnabled) {
                const { data: versions } = await axios
                    .get(
                        `https://get.coollabs.io/versions.json`
                        , {
                            params: {
                                appId: process.env['COOLIFY_APP_ID'] || undefined,
                                version: currentVersion
                            }
                        })
                const latestVersion = versions['coolify'].main.version;
                const isUpdateAvailable = compareVersions(latestVersion, currentVersion);
                if (isUpdateAvailable === 1) {
                    const activeCount = 0
                    if (activeCount === 0) {
                        if (!isDev) {
                            console.log(`Updating Coolify to ${latestVersion}.`);
                            await asyncExecShell(`docker pull coollabsio/coolify:${latestVersion}`);
                            await asyncExecShell(`env | grep COOLIFY > .env`);
                            await asyncExecShell(
                                `docker run --rm -tid --env-file .env -v /var/run/docker.sock:/var/run/docker.sock -v coolify-db coollabsio/coolify:${latestVersion} /bin/sh -c "env | grep COOLIFY > .env && echo 'TAG=${latestVersion}' >> .env && docker stop -t 0 coolify && docker rm coolify && docker compose up -d --force-recreate"`
                            );
                        } else {
                            console.log('Updating (not really in dev mode).');
                        }
                    }
                }
            }
        } catch (error) {
            console.log(error);
        } finally {
            await prisma.$disconnect();
            if (parentPort) parentPort.postMessage('done');
        }

    } else process.exit(0);
})();
