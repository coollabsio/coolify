<script lang="ts">
	export let service: any;
	export let readOnly: any;

	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import Explainer from '$lib/components/Explainer.svelte';
	import { appSession, status } from '$lib/store';
	import { t } from '$lib/translations';
	import ServiceStatus from '../ServiceStatus.svelte';
	let serviceStatus = {
		isExited: false,
		isRunning: false,
		isRestarting: false,
		isStopped: false
	};

	$: if (Object.keys($status.service.statuses).length > 0) {
		let { isExited, isRunning, isRestarting } = $status.service.statuses[service.id].status;
		serviceStatus.isExited = isExited;
		serviceStatus.isRunning = isRunning;
		serviceStatus.isRestarting = isRestarting;
		serviceStatus.isStopped = !isExited && !isRunning && !isRestarting;
	}
</script>

<div class="flex flex-row border-b border-coolgray-500 my-6 space-x-2">
	<div class="title font-bold pb-3">Plausible Analytics</div>
	<ServiceStatus id={service.id} />
</div>
<div class="space-y-2 px-4">
	<div class="grid grid-cols-2 items-center ">
		<label for="scriptName"
			>Script Name <Explainer
				explanation="Useful if you would like to rename the collector script to prevent it blocked by AdBlockers."
			/></label
		>
		<input
			class="w-full"
			name="scriptName"
			id="scriptName"
			readonly={!$appSession.isAdmin && !$status.service.isRunning}
			disabled={!$appSession.isAdmin || $status.service.isRunning || $status.service.initialLoading}
			placeholder="plausible.js"
			bind:value={service.plausibleAnalytics.scriptName}
			required
		/>
	</div>
	<div class="grid grid-cols-2 items-center ">
		<label for="email">{$t('forms.email')}</label>
		<input
			class="w-full"
			name="email"
			id="email"
			disabled={!$appSession.isAdmin || $status.service.isRunning || $status.service.initialLoading}
			readonly={readOnly}
			placeholder={$t('forms.email')}
			bind:value={service.plausibleAnalytics.email}
			required
		/>
	</div>
	<div class="grid grid-cols-2 items-center ">
		<label for="username">{$t('forms.username')}</label>
		<CopyPasswordField
			name="username"
			id="username"
			disabled={!$appSession.isAdmin || $status.service.isRunning || $status.service.initialLoading}
			readonly={readOnly}
			placeholder={$t('forms.username')}
			bind:value={service.plausibleAnalytics.username}
			required
		/>
	</div>
	<div class="grid grid-cols-2 items-center ">
		<label for="password">{$t('forms.password')}</label>
		<CopyPasswordField
			id="password"
			isPasswordField
			readonly
			disabled
			name="password"
			value={service.plausibleAnalytics.password}
		/>
	</div>
</div>

<div class="flex flex-row border-b border-coolgray-500 my-6 space-x-2">
	<div class="title font-bold pb-3">PostgreSQL</div>
	<ServiceStatus id={`${service.id}-postgresql`} />
</div>
<div class="space-y-2 px-4">
	<div class="grid grid-cols-2 items-center ">
		<label for="postgresqlUser">{$t('forms.username')}</label>
		<CopyPasswordField
			name="postgresqlUser"
			id="postgresqlUser"
			value={service.plausibleAnalytics.postgresqlUser}
			readonly
			disabled
		/>
	</div>
	<div class="grid grid-cols-2 items-center ">
		<label for="postgresqlPassword">{$t('forms.password')}</label>
		<CopyPasswordField
			id="postgresqlPassword"
			isPasswordField
			readonly
			disabled
			name="postgresqlPassword"
			value={service.plausibleAnalytics.postgresqlPassword}
		/>
	</div>
	<div class="grid grid-cols-2 items-center ">
		<label for="postgresqlDatabase">{$t('index.database')}</label>
		<CopyPasswordField
			name="postgresqlDatabase"
			id="postgresqlDatabase"
			value={service.plausibleAnalytics.postgresqlDatabase}
			readonly
			disabled
		/>
	</div>
</div>
<div class="flex flex-row my-6 space-x-2">
	<div class="title font-bold pb-3">ClickHouse</div>
	<ServiceStatus id={`${service.id}-clickhouse`} />
</div>