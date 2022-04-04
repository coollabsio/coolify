<script lang="ts">
	export let userCount: number;

	import { browser } from '$app/env';
	import { goto } from '$app/navigation';
	import { session } from '$app/stores';
	import { post } from '$lib/api';
	import { errorNotification } from '$lib/form';
	import { t } from '$lib/translations';
	import { onMount } from 'svelte';

	let loading = false;
	let emailEl;
	let email, password, passwordCheck;

	if (browser && $session.userId) {
		goto('/');
	}
	onMount(() => {
		emailEl.focus();
	});
	async function handleSubmit() {
		// Prevent double submission
		if (loading) return;

		if (password !== passwordCheck) {
			return errorNotification($t('forms.passwords_not_match'));
		}
		loading = true;
		try {
			await post(`/login.json`, {
				email: email.toLowerCase(),
				password,
				isLogin: false
			});
			return window.location.replace('/');
		} catch ({ error }) {
			return errorNotification(error);
		} finally {
			loading = false;
		}
	}
</script>

<div class="icons fixed top-0 left-0 m-3 cursor-pointer" on:click={() => goto('/')}>
	<svg
		xmlns="http://www.w3.org/2000/svg"
		class="h-6 w-6"
		viewBox="0 0 24 24"
		stroke-width="1.5"
		stroke="currentColor"
		fill="none"
		stroke-linecap="round"
		stroke-linejoin="round"
	>
		<path stroke="none" d="M0 0h24v24H0z" fill="none" />
		<line x1="5" y1="12" x2="19" y2="12" />
		<line x1="5" y1="12" x2="11" y2="18" />
		<line x1="5" y1="12" x2="11" y2="6" />
	</svg>
</div>
<div class="flex h-screen flex-col items-center justify-center">
	{#if $session.userId}
		<div class="flex justify-center px-4 text-xl font-bold">{$t('login.already_logged_in')}</div>
	{:else}
		<div class="flex justify-center px-4">
			<form on:submit|preventDefault={handleSubmit} class="flex flex-col py-4 space-y-2">
				<div class="text-6xl font-bold border-gradient w-48 mx-auto border-b-4">Coolify</div>
				<div class="text-xs text-center font-bold pb-10">v{$session.version}</div>
				<input
					type="email"
					name="email"
					placeholder={$t('forms.email')}
					autocomplete="off"
					required
					bind:this={emailEl}
					bind:value={email}
				/>
				<input
					type="password"
					name="password"
					placeholder={$t('forms.password')}
					bind:value={password}
					required
				/>
				<input
					type="password"
					name="passwordCheck"
					placeholder={$t('forms.password_again')}
					bind:value={passwordCheck}
					required
				/>

				<div class="flex space-x-2 h-8 items-center justify-center pt-8">
					<button
						type="submit"
						class="hover:bg-coollabs-100 text-white"
						disabled={loading}
						class:bg-transparent={loading}
						class:text-stone-600={loading}
						class:bg-coollabs={!loading}>{loading ? $t('register.registering') : $t('register.register')}</button
					>
				</div>
			</form>
		</div>
		{#if userCount === 0}
			<div class="pt-5">
				{$t('register.first_user')}
			</div>
		{/if}
	{/if}
</div>
