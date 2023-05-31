<div x-data="{ deleteApplication: false }">
    <h2 class="pb-0">Danger Zone</h2>
    <div class="text-sm">Woah. I hope you know what are you doing.</div>
    <h3 class="pb-0">Delete Application</h3>
    <div class="text-sm">This will stop your containers, delete all related data, etc. Beware! There is no coming back!
    </div>
    <x-naked-modal show="deleteApplication" />
    <x-forms.button x-on:click.prevent="deleteApplication = true">Delete</x-forms.button>
</div>
