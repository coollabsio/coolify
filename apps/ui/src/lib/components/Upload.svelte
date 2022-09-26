<script lang="ts">
	import { post } from '$lib/api';
	let cert: any;
	let key: any;
	async function submitForm() {
		const formData = new FormData();
		formData.append('cert', cert[0]);
        formData.append('key', key[0]);
		await post('/upload', formData);
	}
</script>

<form on:submit|preventDefault={submitForm}>
    <label for="cert">Certificate</label>
	<input id="cert" type="file" required name="cert" bind:files={cert} />
    <label for="key">Private Key</label>
    <input id="key" type="file" required name="key" bind:files={key} />
	<br />
	<input type="submit" />
</form>
