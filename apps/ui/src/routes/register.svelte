<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async ({ stuff }) => {
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
	if (!$appSession.isRegistrationEnabled) {
		window.location.assign('/');
	}
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

<div class="flex lg:flex-row flex-col h-screen">
	<div class="bg-neutral-focus h-screen lg:flex hidden flex-col justify-end p-20 flex-1">
		<h1 class="title lg:text-6xl mb-5 border-gradient">Coolify</h1>
		<h3 class="title">Made self-hosting simple.</h3>
	</div>
	<div class="flex flex-1 flex-col lg:max-w-2xl">
		<div class="flex flex-row p-8 items-center space-x-3 justify-between">
			<div class="icons cursor-pointer" on:click={() => goto('/')}>
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
			<div class="flex flex-row items-center space-x-3">
				{#if $appSession.whiteLabeledDetails.icon}
					<div class="avatar" style="width: 40px; height: 40px">
						<img
							src={$appSession.whiteLabeledDetails.icon}
							alt="Icon for white labeled version of Coolify"
						/>
					</div>
				{:else}
					<div>
						<div class="avatar" style="width: 40px; height: 40px">
							<img src="favicon.png" alt="Coolify icon" />
						</div>
					</div>
					<div class="prose">
						<h4>Coolify</h4>
					</div>
				{/if}
			</div>
		</div>
		<div
			class="w-full md:px-20 lg:px-10 xl:px-20 p-6 flex flex-col h-full justify-center items-center"
		>
			<div class="mb-5 w-full prose prose-neutral">
				<h1 class="m-0 white">Get started</h1>
				<h5>Enter the required fields to complete the registration.</h5>
			</div>
			<form on:submit|preventDefault={handleSubmit} class="flex flex-col py-4 space-y-3 w-full">
				<input
					type="email"
					name="email"
					placeholder={$t('forms.email')}
					autocomplete="off"
					required
					bind:this={emailEl}
					bind:value={email}
					class="w-full"
				/>
				<input
					type="password"
					name="password"
					placeholder={$t('forms.password')}
					bind:this={passwordEl}
					bind:value={password}
					required
					class="w-full"
				/>
				<input
					type="password"
					name="passwordCheck"
					placeholder={$t('forms.password_again')}
					bind:value={passwordCheck}
					required
					class="w-full"
				/>

				<div class="flex space-y-3 flex-col pt-3">
					<button
						type="submit"
						class="btn"
						disabled={loading}
						class:bg-transparent={loading}
						class:bg-coollabs={!loading}
						class:loading>{loading ? $t('register.registering') : $t('register.register')}</button
					>
				</div>
			</form>
			{#if userCount === 0}
				<div class="pt-5">
					{$t('register.first_user')}
				</div>
			{/if}
		</div>
	</div>
</div>
