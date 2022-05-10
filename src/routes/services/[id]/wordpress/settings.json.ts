import { dev } from '$app/env';
import { asyncExecShell, getEngine, getUserDetails } from '$lib/common';
import { decrypt, encrypt } from '$lib/crypto';
import * as db from '$lib/database';
import { ErrorHandler, generatePassword, getFreePort } from '$lib/database';
import { checkContainer, startTcpProxy, stopTcpHttpProxy } from '$lib/haproxy';
import type { ComposeFile } from '$lib/types/composeFile';
import type { RequestHandler } from '@sveltejs/kit';
import cuid from 'cuid';
import fs from 'fs/promises';
import yaml from 'js-yaml';

export const post: RequestHandler = async (event) => {
	const { status, body, teamId } = await getUserDetails(event);
	if (status === 401) return { status, body };

	const { id } = event.params;

	const { ownMysql } = await event.request.json();
	try {
		await db.prisma.wordpress.update({
			where: { serviceId: id },
			data: { ownMysql }
		});
		return {
			status: 201
		};
	} catch (error) {
		console.log(error);
		return ErrorHandler(error);
	}
};
