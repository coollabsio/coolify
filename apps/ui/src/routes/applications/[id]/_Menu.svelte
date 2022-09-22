<script lang="ts">
	import { page } from '$app/stores';
</script>

<ul class="menu border bg-coolgray-100 border-coolgray-200 rounded-box p-2 space-y-2">
	<li class="menu-title">
		<span>General</span>
	</li>
	<li class="rounded" class:bg-coollabs={$page.url.pathname === `/applications/${$page.params.id}`}>
		<a href={`/applications/${$page.params.id}`} class="no-underline w-full">Build & Deploy</a>
	</li>
	<li class="menu-title">
		<span>Other</span>
	</li>
	<li
		class="rounded"
		class:bg-coollabs={$page.url.pathname === `/applications/${$page.params.id}/features`}
	>
		<a href={`/applications/${$page.params.id}/features`} class="no-underline w-full">Features</a>
	</li>
</ul>
