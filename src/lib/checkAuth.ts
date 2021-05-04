export function checkAuth({ session, path }) {
	if (!session.isLoggedIn) {
		return {
			status: 302,
			redirect: '/'
		};
	}
	return {
		props: {
			path
		}
	}
}
