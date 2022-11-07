<script lang="ts">
	export let type: string;
	export let isAbsolute = true;

	let extension = 'png';
	let svgs = [
		'languagetool',
		'meilisearch',
		'n8n',
		'glitchtip',
		'searxng',
		'umami',
		'uptimekuma',
		'vaultwarden',
		'weblate',
		'wordpress'
	];

	const name: any =
		type &&
		(type[0].toUpperCase() + type.substring(1).toLowerCase())
			.replaceAll('.', '')
			.replaceAll(' ', '')
			.split('-')[0]
			.toLowerCase();

	if (svgs.includes(name)) {
		extension = 'svg';
	}

	function generateClass() {
		switch (name) {
			case 'n8n':
				return 'w-12 h-12 -mt-3';
			case 'weblate':
				return 'w-12 h-12 -mt-3';
			default:
				return isAbsolute ? 'w-10 h-10' : 'w-8 h-8 mx-auto';
		}
	}
</script>

{#if name}
	<img class={generateClass()} src={`/icons/${name}.${extension}`} alt={`Icon of ${name}`} />
{/if}
