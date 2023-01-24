<script lang="ts">
	export let type: string;
	export let isAbsolute = false;
	let githubRawIconUrl =
		'https://raw.githubusercontent.com/coollabsio/coolify-community-templates/main/services/icons';

	let fallback = '/icons/default.png';
	let useFallback: boolean = false;

	const handleError = (ev: { target: { src: string } }) => {
		if (useFallback) {
			ev.target.src = fallback;
		} else {
			ev.target.src = `${githubRawIconUrl}/${name}.svg`;
			useFallback = true;
		}
	};

	const name: any =
		type &&
		(type[0].toUpperCase() + type.substring(1).toLowerCase())
			.replaceAll('.', '')
			.replaceAll(' ', '')
			.split('-')[0]
			.toLowerCase();

	function generateClass() {
		return isAbsolute ? 'w-10 h-10 absolute -m-4 -mt-9 left-0' : 'w-10 h-10';
	}
</script>

{#if name}
	<img
		class={generateClass()}
		src={`${githubRawIconUrl}/${name}.png`}
		on:error={handleError}
		alt={`Icon of ${name}`}
	/>
{/if}
