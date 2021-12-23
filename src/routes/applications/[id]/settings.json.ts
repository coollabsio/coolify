import { getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { status, body } = await getUserDetails(request);
    if (status === 401) return { status, body }

    const { id } = request.params
    const debugLogs = request.body.get('debugLogs') === 'true' ? true : false
    const mergepullRequestDeployments = request.body.get('mergepullRequestDeployments') === 'true' ? true : false

    try {
        return await db.setApplicationSettings({ id, debugLogs, mergepullRequestDeployments })
    } catch (err) {
        return err
    }

}