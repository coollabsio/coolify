<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ fetch, params, stuff }) => {
		return {
			props: { ...stuff }
		};
	};
</script>

<script lang="ts">
	export let userCount: number;

	import { goto } from '$app/navigation';
	import { post } from '$lib/api';
	import { errorNotification } from '$lib/common';
	import { appSession, loginEmail } from '$lib/store';
	import { t } from '$lib/translations';
	import { onMount } from 'svelte';
	import Cookies from 'js-cookie';

	let loading = false;
	let emailEl: HTMLInputElement;
	let passwordEl: HTMLInputElement;
	let email: string | undefined, password: string, passwordCheck: string;

	onMount(() => {
		email = $loginEmail;
		if (email) {
			passwordEl.focus();
		} else {
			emailEl.focus();
		}
	});
	async function handleSubmit() {
		// Prevent double submission
		if (loading) return;

		if (password !== passwordCheck) {
			return errorNotification($t('forms.passwords_not_match'));
		}
		loading = true;
		try {
			const { token, payload } = await post(`/login`, {
				email: email?.toLowerCase(),
				password,
				isLogin: false
			});
			Cookies.set('token', token, {
				path: '/'
			});
			$appSession.teamId = payload.teamId;
			$appSession.userId = payload.userId;
			$appSession.permission = payload.permission;
			$appSession.isAdmin = payload.isAdmin;
			return await goto('/');
		} catch (error) {
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
	{#if $appSession.userId}
		<div class="flex justify-center px-4 text-xl font-bold">{$t('login.already_logged_in')}</div>
	{:else}
		<div class="flex justify-center px-4">
			<form on:submit|preventDefault={handleSubmit} class="flex flex-col py-4 space-y-2">
				{#if $appSession.whiteLabeledDetails.icon}
					<img
						class="w-32 mx-auto pb-8"
						src={$appSession.whiteLabeledDetails.icon}
						alt="Icon for white labeled version of Coolify"
					/>
				{:else}
					<div class="text-6xl font-bold border-gradient w-48 mx-auto border-b-4 mb-8">Coolify</div>
				{/if}
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
					bind:this={passwordEl}
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
						class:bg-coollabs={!loading}
						>{loading ? $t('register.registering') : $t('register.register')}</button
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
