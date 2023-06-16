<div x-data="{ deleteApplication: false }">
    <x-naked-modal show="deleteApplication" title="Delete Application"
        message='This application will be deleted. It is not reversible. <br>Please think again.' />
    <h2>Danger Zone</h2>
    <div class="">Woah. I hope you know what are you doing.</div>
    <h3 class="pt-4">Delete Application</h3>
    <div class="pb-4">This will stop your containers, delete all related data, etc. Beware! There is no coming
        back!
    </div>
    <x-forms.button isWarning x-on:click.prevent="deleteApplication = true">Delete</x-forms.button>
</div>
