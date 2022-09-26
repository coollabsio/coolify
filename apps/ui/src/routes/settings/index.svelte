<script lang="ts">
	import { goto } from '$app/navigation';
	import { appSession } from '$lib/store';
	if ($appSession.teamId !== '0') {
		goto('/settings/ssh');
	}
	goto('/settings/coolify');
</script>
