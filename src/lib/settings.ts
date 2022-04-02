const publicPaths = [
	'/login',
	'/register',
	'/reset',
	'/reset/password',
	'/webhooks/success',
	'/webhooks/github',
	'/webhooks/github/install',
	'/webhooks/gitlab'
];

export function isPublicPath(path: string): boolean {
	return publicPaths.includes(path);
}
