<div>
    <x-modal yesOrNo modalId="deleteProject" modalTitle="Delete Project">
        <x-slot:modalBody>
            <p>This project will be deleted. It is not reversible. <br>Please think again.</p>
        </x-slot:modalBody>
    </x-modal>
    <x-forms.button isError isModal modalId="deleteProject">
        Delete Project
    </x-forms.button>
</div>
