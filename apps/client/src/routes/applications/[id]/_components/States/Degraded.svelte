<script lang="ts">
	export let id: string;
	import * as Buttons from '../Buttons';
</script>

<a href={`/applications/${id}/logs`} class="btn btn-sm text-sm gap-2">
	<svg
		xmlns="http://www.w3.org/2000/svg"
		class="w-6 h-6 text-red-500"
		viewBox="0 0 24 24"
		stroke-width="1.5"
		stroke="currentcolor"
		fill="none"
		stroke-linecap="round"
		stroke-linejoin="round"
	>
		<path stroke="none" d="M0 0h24v24H0z" fill="none" />
		<path
			d="M8.7 3h6.6c.3 0 .5 .1 .7 .3l4.7 4.7c.2 .2 .3 .4 .3 .7v6.6c0 .3 -.1 .5 -.3 .7l-4.7 4.7c-.2 .2 -.4 .3 -.7 .3h-6.6c-.3 0 -.5 -.1 -.7 -.3l-4.7 -4.7c-.2 -.2 -.3 -.4 -.3 -.7v-6.6c0 -.3 .1 -.5 .3 -.7l4.7 -4.7c.2 -.2 .4 -.3 .7 -.3z"
		/>
		<line x1="12" y1="8" x2="12" y2="12" />
		<line x1="12" y1="16" x2="12.01" y2="16" />
	</svg>
	Application Error (check logs)
</a>
<Buttons.Stop {id} />
