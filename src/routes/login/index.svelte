<script lang="ts">
	import { browser } from '$app/env';
	import { goto } from '$app/navigation';
	import { session } from '$app/stores';
	import { enhance, errorNotification } from '$lib/form';
	let loading = false;
	let passwordEl;
	if (browser && $session.token) {
		goto('/');
	}
</script>

{#if $session.token}
	<div class="flex justify-center px-4 text-xl font-bold">Already logged in...</div>
{:else}
	<div class="flex justify-center px-4">
		<form
			action="/login.json"
			method="post"
			use:enhance={{
				result: async () => {
					window.location.replace('/');
				},
				pending: async () => {
					loading = true;
				},
				error: async (res) => {
					const { message } = await res.json();
					errorNotification(message);
					passwordEl.value = '';
					loading = false;
				}
			}}
			class="flex flex-col py-4 space-y-2"
		>
			<input type="text" name="email" placeholder="email" required />
			<input type="text" name="password" placeholder="password" bind:this={passwordEl} required />
			<div class="flex space-x-2 h-8 items-center justify-center">
				<button type="submit" disabled={loading}>{loading ? 'Authenticating...' : 'Login'}</button>
			</div>
		</form>
	</div>
{/if}
