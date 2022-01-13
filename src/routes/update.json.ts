import { dev } from '$app/env';
import { asyncExecShell, version } from '$lib/common';
import type { RequestHandler } from '@sveltejs/kit';
import compare from 'compare-versions';
import got from "got"

export const get: RequestHandler = async (request) => {
    try {
        const currentVersion = version;
        const versions = await got.get(`https://get.coollabs.io/version.json?appId=${process.env['COOLIFY_APP_ID']}`).json()
        const latestVersion = versions["coolify-v2"].main.version;
        const isUpdateAvailable = compare(latestVersion, currentVersion)
        return {
            body: {
                // isUpdateAvailable: isUpdateAvailable === 1,
                isUpdateAvailable: true,
                latestVersion,
            }
        };
    } catch (err) {
        return err
    }
}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    try {
        const versions = await got.get(`https://get.coollabs.io/version.json?appId=${process.env['COOLIFY_APP_ID']}`).json()
        const latestVersion = versions["coolify-v2"].main.version;
        if (!dev) {
            await asyncExecShell(`env | grep COOLIFY > .env`)
            await asyncExecShell(`docker compose pull coollabsio/coolify:${latestVersion}`);
            await asyncExecShell(`docker run -tid --env-file .env -v /var/run/docker.sock:/var/run/docker.sock -v coolify-db-sqlite coollabsio/coolify:${latestVersion} /bin/sh -c "env | grep COOLIFY > .env && docker compose up -d --force-recreate"`)
            return {
                status: 200
            }
        } else {
            console.log('dev mode')
            console.log(latestVersion)
            return {
                status: 200
            }
        }
    } catch (error) {
        return {
            status: 500,
            body: {
                message: error.message || error
            }
        }
    }
}