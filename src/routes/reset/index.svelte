<script lang="ts">
	import { goto } from '$app/navigation';
	import { get, post } from '$lib/api';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { errorNotification } from '$lib/form';
	import { toast } from '@zerodevx/svelte-toast';

	let secretKey;
	let password = false;
	let users = [];

	async function handleSubmit() {
		try {
			await post(`/reset.json`, { secretKey });
			password = true;
			const data = await get('/reset.json');
			users = data.users;
			return;
		} catch ({ error }) {
			return errorNotification(error);
		}
	}
	async function resetPassword(user) {
		try {
			await post(`/reset/password.json`, { secretKey, user });
			toast.push('Password reset done.');
			return;
		} catch ({ error }) {
			return errorNotification(error);
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
<div class="pb-10 pt-24 text-center text-4xl font-bold">Reset Password</div>
<div class="flex items-center justify-center">
	{#if password}
		<table class="mx-2 text-left">
			<thead class="mb-2">
				<tr>
					<th class="px-2">Email</th>
					<th>New password</th>
				</tr>
			</thead>
			<tbody>
				{#each users as user}
					<tr>
						<td class="px-2">{user.email}</td>
						<td class="flex space-x-2">
							<input
								id="newPassword"
								name="newPassword"
								bind:value={user.newPassword}
								placeholder="Super secure new password"
							/>
							<button
								class="mx-auto my-4 w-32 bg-coollabs hover:bg-coollabs-100"
								on:click={() => resetPassword(user)}>Reset</button
							></td
						>
					</tr>
				{/each}
			</tbody>
		</table>
	{:else}
		<form class="flex flex-col" on:submit|preventDefault={handleSubmit}>
			<div class="text-center text-2xl py-2 font-bold">Secret Key</div>
			<CopyPasswordField
				isPasswordField={true}
				id="secretKey"
				name="secretKey"
				bind:value={secretKey}
				placeholder="You can find it in ~/coolify/.env (COOLIFY_SECRET_KEY)"
			/>
			<button type="submit" class="bg-coollabs hover:bg-coollabs-100 mx-auto w-32 my-4"
				>Submit</button
			>
		</form>
	{/if}
</div>
