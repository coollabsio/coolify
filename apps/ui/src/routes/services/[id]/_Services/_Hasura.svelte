<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { t } from '$lib/translations';
	export let service: any;
</script>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Hasura</div>
</div>

<div class="grid grid-cols-2 items-center px-10">
	<label for="graphQLAdminPassword">GraphQL Admin Password</label>
	<CopyPasswordField
		name="graphQLAdminPassword"
		id="graphQLAdminPassword"
		isPasswordField
		value={service.hasura.graphQLAdminPassword}
		readonly
		disabled
	/>
</div>

<div class="flex space-x-1 py-5 font-bold">
	<div class="title">PostgreSQL</div>
</div>

<div class="grid grid-cols-2 items-center px-10">
	<label for="postgresqlUser">{$t('forms.username')}</label>
	<CopyPasswordField
		name="postgresqlUser"
		id="postgresqlUser"
		value={service.hasura.postgresqlUser}
		readonly
		disabled
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="postgresqlPassword">{$t('forms.password')}</label>
	<CopyPasswordField
		id="postgresqlPassword"
		isPasswordField
		readonly
		disabled
		name="postgresqlPassword"
		value={service.hasura.postgresqlPassword}
	/>
</div>
<div class="grid grid-cols-2 items-center px-10">
	<label for="postgresqlDatabase">{$t('index.database')}</label>
	<CopyPasswordField
		name="postgresqlDatabase"
		id="postgresqlDatabase"
		value={service.hasura.postgresqlDatabase}
		readonly
		disabled
	/>
</div>
