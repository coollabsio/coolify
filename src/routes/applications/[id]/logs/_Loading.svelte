<div class="lds-ripple absolute left-0">
	<div />
	<div />
</div>

<style>
	.lds-ripple {
		display: inline-block;
		position: relative;
		left: -19px;
		top: -8px;
		width: 40px;
		height: 40px;
	}
	.lds-ripple div {
		position: absolute;
		border: 4px solid #fff;
		opacity: 1;
		border-radius: 50%;
		animation: lds-ripple 1s cubic-bezier(0, 0.2, 0.8, 1) infinite;
	}
	.lds-ripple div:nth-child(2) {
		animation-delay: -0.5s;
	}
	@keyframes lds-ripple {
		0% {
			top: 1px;
			left: 1px;
			width: 0;
			height: 0;
			opacity: 1;
		}
		100% {
			top: 0px;
			left: 0px;
			width: 36px;
			height: 36px;
			opacity: 0;
		}
	}
</style>
