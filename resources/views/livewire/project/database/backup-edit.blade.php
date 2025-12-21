<form wire:submit="submit">
    <div class="flex gap-2 pb-2">
        <h2>Scheduled Backup</h2>
        <x-forms.button type="submit">
            {{ __('common.save') }}
        </x-forms.button>
        @if (str($status)->startsWith('running'))
            <livewire:project.database.backup-now :backup="$backup" />
        @endif
        @if ($backup->database_id !== 0)
            <x-modal-confirmation title="{{ __('modal.confirm_backup_schedule_deletion') }}" buttonTitle="{{ __('modal.delete_backups_and_schedule') }}"
                isErrorButton submitAction="delete" :checkboxes="$checkboxes" :actions="[
                    'The selected backup schedule will be deleted.',
                    'Scheduled backups for this database will be stopped (if this is the only backup schedule for this database).',
                ]"
                confirmationText="{{ $backup->database->name }}"
                confirmationLabel="{{ __('database.confirm_database_name_label') }}"
                shortConfirmationLabel="{{ __('database.database_name_short') }}" />
        @endif
    </div>
    <div class="w-64 pb-2">
        <x-forms.checkbox instantSave label="{{ __('database.backup_enabled') }}" id="backupEnabled" />
        @if ($s3s->count() > 0)
            <x-forms.checkbox instantSave label="{{ __('database.s3_enabled') }}" id="saveS3" />
        @else
            <x-forms.checkbox instantSave helper="{{ __('database.no_validated_s3_storage') }}" label="{{ __('database.s3_enabled') }}" id="saveS3"
                disabled />
        @endif
        @if ($backup->save_s3)
            <x-forms.checkbox instantSave label="{{ __('database.disable_local_backup') }}" id="disableLocalBackup"
                helper="{{ __('database.disable_local_backup_helper') }}" />
        @else
            <x-forms.checkbox disabled label="{{ __('database.disable_local_backup') }}" id="disableLocalBackup"
                helper="{{ __('database.disable_local_backup_helper') }}" />
        @endif
    </div>
    @if ($backup->save_s3)
        <div class="pb-6">
            <x-forms.select id="s3StorageId" label="{{ __('database.s3_storage') }}" required>
                <option value="default" disabled>{{ __('database.select_s3_storage') }}</option>
                @foreach ($s3s as $s3)
                    <option value="{{ $s3->id }}">{{ $s3->name }}</option>
                @endforeach
            </x-forms.select>
        </div>
    @endif
    <div class="flex flex-col gap-2">
        <h3>{{ __('database.settings') }}</h3>
        <div class="flex gap-2 flex-col ">
            @if ($backup->database_type === 'App\Models\StandalonePostgresql' && $backup->database_id !== 0)
                <div class="w-48">
                    <x-forms.checkbox label="{{ __('database.backup_all_databases') }}" id="dumpAll" instantSave />
                </div>
                @if (!$backup->dump_all)
                    <x-forms.input label="{{ __('database.databases_to_backup') }}"
                        helper="{{ __('database.databases_to_backup_helper') }}"
                        id="databasesToBackup" />
                @endif
            @elseif($backup->database_type === 'App\Models\StandaloneMongodb')
                <x-forms.input label="{{ __('database.databases_to_include') }}"
                    helper="{{ __('database.databases_to_include_helper') }}"
                    id="databasesToBackup" />
            @elseif($backup->database_type === 'App\Models\StandaloneMysql')
                <div class="w-48">
                    <x-forms.checkbox label="{{ __('database.backup_all_databases') }}" id="dumpAll" instantSave />
                </div>
                @if (!$backup->dump_all)
                    <x-forms.input label="{{ __('database.databases_to_backup') }}"
                        helper="{{ __('database.databases_to_backup_helper') }}"
                        id="databasesToBackup" />
                @endif
            @elseif($backup->database_type === 'App\Models\StandaloneMariadb')
                <div class="w-48">
                    <x-forms.checkbox label="{{ __('database.backup_all_databases') }}" id="dumpAll" instantSave />
                </div>
                @if (!$backup->dump_all)
                    <x-forms.input label="{{ __('database.databases_to_backup') }}"
                        helper="{{ __('database.databases_to_backup_helper') }}"
                        id="databasesToBackup" />
                @endif
            @endif
        </div>
        <div class="flex gap-2">
            <x-forms.input label="{{ __('database.frequency') }}" id="frequency" />
            <x-forms.input label="{{ __('database.timezone') }}" id="timezone" disabled
                helper="{{ __('database.timezone_helper') }}" />
            <x-forms.input label="{{ __('database.timeout') }}" id="timeout" helper="{{ __('database.timeout_helper') }}" />
        </div>

        <h3 class="mt-6 mb-2 text-lg font-medium">{{ __('database.backup_retention_settings') }}</h3>
        <div class="mb-4">
            <ul class="list-disc pl-6 space-y-2">
                <li>{{ __('database.retention_unlimited_note') }}</li>
                <li>{{ __('database.retention_rules_note') }}</li>
            </ul>
        </div>

        <div class="flex gap-6 flex-col">
            <div>
                <h4 class="mb-3 font-medium">{{ __('database.local_backup_retention') }}</h4>
                <div class="flex gap-2">
                    <x-forms.input label="{{ __('database.number_of_backups_to_keep') }}" id="databaseBackupRetentionAmountLocally"
                        type="number" min="0"
                        helper="{{ __('database.number_of_backups_to_keep_helper') }}" />
                    <x-forms.input label="{{ __('database.days_to_keep_backups') }}" id="databaseBackupRetentionDaysLocally" type="number"
                        min="0"
                        helper="{{ __('database.days_to_keep_backups_helper') }}" />
                    <x-forms.input label="{{ __('database.maximum_storage_gb') }}" id="databaseBackupRetentionMaxStorageLocally"
                        type="number" min="0"
                        helper="{{ __('database.maximum_storage_gb_helper') }}" />
                </div>
            </div>

            @if ($backup->save_s3)
                <div>
                    <h4 class="mb-3 font-medium">{{ __('database.s3_storage_retention') }}</h4>
                    <div class="flex gap-2">
                        <x-forms.input label="{{ __('database.number_of_backups_to_keep_s3') }}" id="databaseBackupRetentionAmountS3"
                            type="number" min="0"
                            helper="{{ __('database.number_of_backups_to_keep_s3_helper') }}" />
                        <x-forms.input label="{{ __('database.days_to_keep_backups_s3') }}" id="databaseBackupRetentionDaysS3" type="number"
                            min="0"
                            helper="{{ __('database.days_to_keep_backups_s3_helper') }}" />
                        <x-forms.input label="{{ __('database.maximum_storage_gb_s3') }}" id="databaseBackupRetentionMaxStorageS3"
                            type="number" min="0"
                            helper="{{ __('database.maximum_storage_gb_s3_helper') }}" />
                    </div>
                </div>
            @endif
        </div>
    </div>
</form>
