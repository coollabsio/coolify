<div>
    <x-modal yesOrNo modalId="{{ $modalId }}" modalTitle="Delete Application">
        <x-slot:modalBody>
            <p>This application will be deleted. It is not reversible. <br>Please think again.</p>
        </x-slot:modalBody>
    </x-modal>
    <h3>Danger Zone</h3>
    <div class="">Woah. I hope you know what are you doing.</div>
    <h4 class="pt-4">Delete Application</h4>
    <div class="pb-4">This will stop your containers, delete all related data, etc. Beware! There is no coming
        back!
    </div>
    <x-forms.button isError isModal modalId="{{ $modalId }}">
        Delete
    </x-forms.button>
</div>
