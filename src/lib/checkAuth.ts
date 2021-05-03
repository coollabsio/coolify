export function checkAuth({ session }) {
	if (!session.isLoggedIn) {
		return {
			status: 302,
			redirect: '/'
		};
	}
	return true;
}
