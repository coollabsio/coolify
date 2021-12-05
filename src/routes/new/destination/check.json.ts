import * as db from '$lib/database';
import { isDockerNetworkExists } from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const network = request.body.get('network')
    try {
        return await isDockerNetworkExists({ network })
    } catch (err) {
        console.log(err)
        return err
    }
}