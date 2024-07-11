<div>
    <h2>Danger Zone</h2>
    <div class="">Woah. I hope you know what are you doing.</div>
    <h4 class="pt-4">Delete Resource</h4>
    <div class="pb-4">This will stop your containers, delete all related data, etc. Beware! There is no coming
        back!
    </div>
    <x-modal-confirmation isErrorButton buttonTitle="Delete" confirm={{ $confirm }}>
        <div class="px-2">This resource will be deleted. It is not reversible. <strong class="text-error">Please think
                again.</strong><br><br></div>
        <h4>Actions</h4>
        <x-forms.checkbox id="delete_configurations"
            label="Permanently delete configuration files from the server?"></x-forms.checkbox>
        <x-forms.checkbox id="delete_volumes" label="Permanently delete associated volumes?"></x-forms.checkbox>
    </x-modal-confirmation>
</div>
