<div>
    <form wire:submit='submit'>
        <div class="flex items-center gap-2 pb-4">
            @if ($database->human_name)
                <h2>{{ Str::headline($database->human_name) }}</h2>
            @else
                <h2>{{ Str::headline($database->name) }}</h2>
            @endif
            <x-forms.button canGate="update" :canResource="$database" type="submit">Save</x-forms.button>
            @can('update', $database)
                <x-modal-confirmation wire:click="convertToApplication" title="Convert to Application"
                    buttonTitle="Convert to Application" submitAction="convertToApplication" :actions="['The selected resource will be converted to an application.']"
                    confirmationText="{{ Str::headline($database->name) }}"
                    confirmationLabel="Please confirm the execution of the actions by entering the Service Database Name below"
                    shortConfirmationLabel="Service Database Name" />
            @endcan
            @can('delete', $database)
                <x-modal-confirmation title="Confirm Service Database Deletion?" buttonTitle="Delete" isErrorButton
                    submitAction="delete" :actions="['The selected service database container will be stopped and permanently deleted.']" confirmationText="{{ Str::headline($database->name) }}"
                    confirmationLabel="Please confirm the execution of the actions by entering the Service Database Name below"
                    shortConfirmationLabel="Service Database Name" />
            @endcan
        </div>
        <div class="flex flex-col gap-2">
            <div class="flex gap-2">
                <x-forms.input canGate="update" :canResource="$database" label="Name" id="humanName" placeholder="Name"></x-forms.input>
                <x-forms.input canGate="update" :canResource="$database" label="Description" id="description"></x-forms.input>
                <x-forms.input canGate="update" :canResource="$database" required
                    helper="You can change the image you would like to deploy.<br><br><span class='dark:text-warning'>WARNING. You could corrupt your data. Only do it if you know what you are doing.</span>"
                    label="Image" id="image"></x-forms.input>
            </div>
            <div class="flex items-end gap-2">
                <x-forms.input canGate="update" :canResource="$database" placeholder="5432" disabled="{{ $database->is_public }}" id="publicPort"
                    label="Public Port" />
                <x-forms.checkbox canGate="update" :canResource="$database" instantSave id="isPublic" label="Make it publicly available" />
            </div>
            @if ($db_url_public)
                <x-forms.input label="Database IP:PORT (public)"
                    helper="Your credentials are available in your environment variables." type="password" readonly
                    wire:model="db_url_public" />
            @endif
        </div>
        <h3 class="pt-2">Advanced</h3>
        <div class="w-96">
            <x-forms.checkbox canGate="update" :canResource="$database" instantSave="instantSaveExclude" label="Exclude from service status"
                helper="If you do not need to monitor this resource, enable. Useful if this service is optional."
                id="excludeFromStatus"></x-forms.checkbox>
            <x-forms.checkbox canGate="update" :canResource="$database" helper="Drain logs to your configured log drain endpoint in your Server settings."
                instantSave="instantSaveLogDrain" id="isLogDrainEnabled" label="Drain Logs" />
        </div>
    </form>
</div>
