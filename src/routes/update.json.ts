import { asyncExecShell, version } from '$lib/common';
import type { RequestHandler } from '@sveltejs/kit';

import compare from 'compare-versions';
import got from "got"

export const get: RequestHandler = async (request) => {
    try {
        const currentVersion = version;
        const versions = await got.get(`https://get.coollabs.io/version.json`).json()
        const latestVersion = versions["coolify-v2"].main.version;
        const isUpdateAvailable = compare(latestVersion, currentVersion)
        return {
            body: {
                isUpdateAvailable: isUpdateAvailable === 1,
                latestVersion,
            }
        };
    } catch (err) {
        return err
    }
}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const latestVersion = request.body.get('latestVersion');
    try {
        await asyncExecShell(`env | grep COOLIFY > .env && docker run -tid --env-file .env -v /var/run/docker.sock:/var/run/docker.sock -v coolify-db-sqlite coollabsio/coolify:${latestVersion} /bin/sh -c "env | grep COOLIFY > .env && docker-compose up -d"`)
    } catch (err) {
        return err
    }
}