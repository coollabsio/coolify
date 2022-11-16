import { dev } from '$app/env';
import Cookies from 'js-cookie';

export function getAPIUrl() {
	if (GITPOD_WORKSPACE_URL) {
		const { href } = new URL(GITPOD_WORKSPACE_URL);
		const newURL = href.replace('https://', 'https://3001-').replace(/\/$/, '');
		return newURL;
	}
	if (CODESANDBOX_HOST) {
		return `https://${CODESANDBOX_HOST.replace(/\$PORT/, '3001')}`;
	}
	return dev
		? `http://${window.location.hostname}:3001`
		: 'http://localhost:3000';
}
export function getWebhookUrl(type: string) {
	if (GITPOD_WORKSPACE_URL) {
		const { href } = new URL(GITPOD_WORKSPACE_URL);
		const newURL = href.replace('https://', 'https://3001-').replace(/\/$/, '');
		if (type === 'github') {
			return `${newURL}/webhooks/github/events`;
		}
		if (type === 'gitlab') {
			return `${newURL}/webhooks/gitlab/events`;
		}
	}
	if (CODESANDBOX_HOST) {
		const newURL = `https://${CODESANDBOX_HOST.replace(/\$PORT/, '3001')}`;
		if (type === 'github') {
			return `${newURL}/webhooks/github/events`;
		}
		if (type === 'gitlab') {
			return `${newURL}/webhooks/gitlab/events`;
		}
	}
	return `https://webhook.site/0e5beb2c-4e9b-40e2-a89e-32295e570c21/events`;
}
async function send({
	method,
	path,
	data = null,
	headers,
	timeout = 120000
}: {
	method: string;
	path: string;
	data?: any;
	headers?: any;
	timeout?: number;
}): Promise<Record<string, unknown>> {
	const token = Cookies.get('token');
	const controller = new AbortController();
	const id = setTimeout(() => controller.abort(), timeout);
	const opts: any = { method, headers: {}, body: null, signal: controller.signal };
	if (data && Object.keys(data).length > 0) {
		const parsedData = data;
		for (const [key, value] of Object.entries(data)) {
			if (value === '') {
				parsedData[key] = null;
			}
		}
		if (parsedData) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(parsedData);
		}
	}

	if (headers) {
		opts.headers = {
			...opts.headers,
			...headers
		};
	}
	if (token && !path.startsWith('https://')) {
		opts.headers = {
			...opts.headers,
			Authorization: `Bearer ${token}`
		};
	}
	if (!path.startsWith('https://')) {
		path = `/api/v1${path}`;
	}

	if (dev && !path.startsWith('https://')) {
		path = `${getAPIUrl()}${path}`;
	}
	if (method === 'POST' && data && !opts.body) {
		opts.body = data;
	}
	const response = await fetch(`${path}`, opts);

	clearTimeout(id);

	const contentType = response.headers.get('content-type');

	let responseData = {};
	if (contentType) {
		if (contentType?.indexOf('application/json') !== -1) {
			responseData = await response.json();
		} else if (contentType?.indexOf('text/plain') !== -1) {
			responseData = await response.text();
		} else {
			return {};
		}
	} else {
		return {};
	}
	if (!response.ok) {
		if (
			response.status === 401 &&
			!path.startsWith('https://api.github') &&
			!path.includes('/v4/')
		) {
			Cookies.remove('token');
		}

		throw responseData;
	}
	return responseData;
}

export function get(path: string, headers?: Record<string, unknown>): Promise<Record<string, any>> {
	return send({ method: 'GET', path, headers });
}

export function del(
	path: string,
	data: Record<string, unknown>,
	headers?: Record<string, unknown>
): Promise<Record<string, any>> {
	return send({ method: 'DELETE', path, data, headers });
}

export function post(
	path: string,
	data: Record<string, unknown> | FormData,
	headers?: Record<string, unknown>
): Promise<Record<string, any>> {
	return send({ method: 'POST', path, data, headers });
}

export function put(
	path: string,
	data: Record<string, unknown>,
	headers?: Record<string, unknown>
): Promise<Record<string, any>> {
	return send({ method: 'PUT', path, data, headers });
}
