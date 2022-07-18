<script lang="ts">
	import { page } from '$app/stores';
	const token = $page.url.searchParams.get('token');
	localStorage.setItem('gitLabToken', token || '');
	window.close();
</script>
