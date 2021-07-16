<script context="module" lang="ts">
	import { request } from '$lib/request';
	/**
	 * @type {import('@sveltejs/kit').Load}
	 */
	export async function load(session) {
		if (!browser && !process.env.VITE_GITHUB_APP_CLIENTID) {
			return {
				status: 302,
				redirect: '/dashboard'
			};
		}
		return {
			props: {
				initDashboard: await request('/api/v1/dashboard', session)
			}
		};
	}
</script>

<script lang="ts">
	import Databases from './databases.svelte'
    import Applications from './applications.svelte'
    import Services from './services.svelte'
</script>

<Applications />
<Databases />
<Services />

