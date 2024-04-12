<div>
    <h2>Danger Zone</h2>
    <div class="">Woah. I hope you know what are you doing.</div>
    <h4 class="pt-4">Delete Resource</h4>
    <div class="pb-4">This will stop your containers, delete all related data, etc. Beware! There is no coming
        back!
    </div>
    <x-modal-confirmation isErrorButton buttonTitle="Delete">
        <div class="px-2">This resource will be deleted. It is not reversible. <strong class="text-error">Please think
                again.</strong><br><br></div>
        <x-forms.checkbox class="px-0" id="delete_configurations"
            label="Also delete configuration files from the server (/data/coolify/...)?"></x-forms.checkbox>
    </x-modal-confirmation>
</div>
