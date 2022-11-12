<script lang="ts">
	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import { appSession } from '$lib/store';
	import Menu from './_Menu.svelte';
	import ContextMenu from '$lib/components/ContextMenu.svelte';
	import LeftSidebar from '$lib/components/LeftSidebar.svelte';
</script>

<ContextMenu>
	<div class="title">Identity & Access Management</div>
</ContextMenu>

<LeftSidebar>
	<div slot="sidebar"><Menu /></div>
	<div slot="content"><slot /></div>
</LeftSidebar>
