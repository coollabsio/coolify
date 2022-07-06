<script lang="ts">
	export let fullscreen = true;
	export let cover = false;
</script>

{#if fullscreen}
	<div
		class="main fixed left-0 top-0 flex h-full flex-wrap content-center"
		class:bg-coolblack={cover}
	>
		<span class="loader" />
	</div>
{:else}
	<div class="main h-64 py-24 left-0 top-0 flex flex-wrap content-center mx-auto">
		<span class="loader" />
	</div>
{/if}

<style lang="postcss">
	.loader {
		width: 8px;
		height: 40px;
		border-radius: 4px;
		display: block;
		margin: 20px auto;
		position: relative;
		background: currentColor;
		color: #fff;
		box-sizing: border-box;
		animation: animloader 0.3s linear infinite alternate;
	}
	.loader::after,
	.loader::before {
		content: '';
		width: 8px;
		height: 4px;
		border-radius: 4px;
		background: currentColor;
		position: absolute;
		top: 50%;
		transform: translateY(-50%);
		left: 20px;
		box-sizing: border-box;
		animation: animloader2 0.3s linear infinite alternate;
	}

	.loader::before {
		left: -20px;
		animation-delay: 0s;
	}
	@keyframes animloader {
		0% {
			height: 48px;
		}
		100% {
			height: 4px;
		}
	}
	@keyframes animloader2 {
		0% {
			height: 4px;
		}
		100% {
			height: 48px;
		}
	}
</style>
