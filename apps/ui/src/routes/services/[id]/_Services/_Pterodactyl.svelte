<script lang="ts">
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	// import Explainer from '$lib/components/Explainer.svelte';
    import { t } from '$lib/translations';
	export let service: any;
</script>

<div class="space-y-2 mt-2">
    <div class="grid grid-cols-2 items-center lg:px-10 px-2">
        <label for="appKey">App Key</label>
        <CopyPasswordField
            name="appKey"
            id="appKey"
            isPasswordField
            bind:value={service.pterodactyl.appKey}
            readonly
            disabled
        />
    </div>
</div>


<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Admin Account</div>
</div>
<div class="space-y-2">
    <div class="grid grid-cols-2 items-center lg:px-10 px-2">
        <label for="defaultEmail">{$t('forms.email')}</label>
        <input
            class="w-full"
            name="defaultEmail"
            id="defaultEmail"
            required
            bind:value={service.pterodactyl.defaultEmail}
            placeholder={$t('forms.email')}
        />
    </div>
    <div class="grid grid-cols-2 items-center lg:px-10 px-2">
        <label for="defaultUserName">{$t('forms.username')}</label>
        <CopyPasswordField
            name="defaultUserName"
            id="defaultUserName"
            bind:value={service.pterodactyl.defaultUserName}
            readonly
            disabled
        />
    </div>
    <div class="grid grid-cols-2 items-center lg:px-10 px-2">
        <label for="defaultPassword">{$t('forms.password')}</label>
        <CopyPasswordField
            name="defaultPassword"
            id="defaultPassword"
            isPasswordField
            bind:value={service.pterodactyl.defaultPassword}
            readonly
            disabled
        />
    </div>
</div>


<div class="flex space-x-1 py-5 font-bold">
	<div class="title">MariaDB</div>
</div>
<div class="space-y-2">
    <div class="grid grid-cols-2 items-center lg:px-10 px-2">
        <label for="mariadbUser">{$t('forms.username')}</label>
        <CopyPasswordField
            name="mariadbUser"
            id="mariadbUser"
            bind:value={service.pterodactyl.mariadbUser}
            readonly
            disabled
        />
    </div>
    <div class="grid grid-cols-2 items-center lg:px-10 px-2">
        <label for="mariadbPassword">{$t('forms.password')}</label>
        <CopyPasswordField
            id="mariadbPassword"
            isPasswordField
            readonly
            disabled
            name="mariadbPassword"
            bind:value={service.pterodactyl.mariadbPassword}
        />
    </div>
    <div class="grid grid-cols-2 items-center lg:px-10 px-2">
        <label for="mariadbDatabase">{$t('index.database')}</label>
        <input
            class="w-full"
            name="mariadbDatabase"
            id="mariadbDatabase"
            required
            readonly
            disabled
            bind:value={service.pterodactyl.mariadbDatabase}
            placeholder="{$t('forms.eg')}: pterodactyl_db"
        />
    </div>
    <div class="grid grid-cols-2 items-center lg:px-10 px-2">
        <label for="mariadbRootUser">{$t('forms.root_db_user')}</label>
        <CopyPasswordField
            id="mariadbRootUser"
            readonly
            disabled
            name="mariadbRootUser"
            bind:value={service.pterodactyl.mariadbRootUser}
        />
    </div>
    <div class="grid grid-cols-2 items-center lg:px-10 px-2">
        <label for="mariadbRootUserPassword">{$t('forms.root_db_password')}</label>
        <CopyPasswordField
            id="mariadbRootUserPassword"
            isPasswordField
            readonly
            disabled
            name="mariadbRootUserPassword"
            bind:value={service.pterodactyl.mariadbRootUserPassword}
        />
    </div>
</div>


<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Redis</div>
</div>
<div class="space-y-2">
    <div class="grid grid-cols-2 items-center lg:px-10 px-2">
        <label for="redisPassword">{$t('forms.password')}</label>
        <CopyPasswordField
            name="redisPassword"
            id="redisPassword"
            isPasswordField
            bind:value={service.pterodactyl.redisPassword}
            readonly
            disabled
        />
    </div>
</div>


<div class="flex space-x-1 py-5 font-bold">
	<div class="title">Mail SMTP</div>
</div>
<div class="space-y-2">
    <div class="grid grid-cols-2 items-center lg:px-10 px-2">
        <label for="mailDriver">Driver</label>
        <input
            class="w-full"
            name="mailDriver"
            id="mailDriver"
            placeholder="SMTP"
            bind:value={service.pterodactyl.mailDriver}
            required
        />
    </div>
    <div class="grid grid-cols-2 items-center lg:px-10 px-2">
        <label for="mailHost">{$t('forms.host')}</label>
        <input
            class="w-full"
            name="mailHost"
            id="mailHost"
            placeholder="smtp.gmail.com"
            bind:value={service.pterodactyl.mailHost}
            required
        />
    </div>
    <div class="grid grid-cols-2 items-center lg:px-10 px-2">
        <label for="mailPort">{$t('forms.port')}</label>
        <input
            class="w-full"
            name="mailPort"
            id="mailPort"
            placeholder="465"
            bind:value={service.pterodactyl.mailPort}
            required
        />
    </div>
    <div class="grid grid-cols-2 items-center lg:px-10 px-2">
        <label for="mailEncryption">Encryption</label>
        <input
            class="w-full"
            name="mailEncryption"
            id="mailEncryption"
            placeholder="TLS"
            bind:value={service.pterodactyl.mailEncryption}
            required
        />
    </div>
    <div class="grid grid-cols-2 items-center lg:px-10 px-2">
        <label for="mailUser">{$t('forms.user')}</label>
        <input
            class="w-full"
            name="mailUser"
            id="mailUser"
            placeholder="example@gmail.com"
            bind:value={service.pterodactyl.mailUser}
            required
        />
    </div>
    <div class="grid grid-cols-2 items-center lg:px-10 px-2">
        <label for="mailPassword">{$t('forms.password')}</label>
        <CopyPasswordField
            name="mailPassword"
            id="mailPassword"
            isPasswordField
            placeholder={$t('forms.password')}
            bind:value={service.pterodactyl.mailPassword}
            required
        />
    </div>
    <div class="grid grid-cols-2 items-center lg:px-10 px-2">
        <label for="mailFrom">Sender Address</label>
        <input
            class="w-full"
            name="mailFrom"
            id="mailFrom"
            placeholder="example@gmail.com"
            bind:value={service.pterodactyl.mailFrom}
            required
        />
    </div>
</div>