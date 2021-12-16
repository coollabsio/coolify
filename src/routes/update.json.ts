import { asyncExecShell, getTeam, getUserDetails, version } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import compare from 'compare-versions';

export const get: RequestHandler = async (request) => {
    try {
        const currentVersion = version;
        const latestVersion = '2.0.0-rc.2';
        const isUpdateAvailable = compare(latestVersion, currentVersion)
        return {
            body: {
                isUpdateAvailable: isUpdateAvailable === 1
            }
        };
    } catch (err) {
        return err
    }
}

export const post: RequestHandler<Locals, FormData> = async (request) => {
    try {
        const { stdout } = await asyncExecShell('pwd')
        console.log(stdout)
        await asyncExecShell(`docker-compose pull && docker-compose up -d`)
    } catch (err) {
        return err
    }
}