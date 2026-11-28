{{-- The v4 Git options of a cluster application or a Git stack (decision 0033). The Livewire component has saveGitOptions(). --}}
<x-application.settings-subsection title="Git"
    helper="Options applied while the builder fetches the repository. The next build uses them.">
    <div class="grid w-full gap-4 sm:grid-cols-2">
        <x-forms.listbox id="isGitSubmodulesEnabled" label="Submodules" onChange="saveGitOptions"
            canGate="update" :canResource="$resource"
            helper="Allow Git submodules during the build process. Submodules accept only https and ssh URLs."
            :options="[
                ['value' => true, 'label' => 'Clone submodules'],
                ['value' => false, 'label' => 'Skip submodules'],
            ]" />
        <x-forms.listbox id="isGitLfsEnabled" label="Git LFS" onChange="saveGitOptions"
            canGate="update" :canResource="$resource"
            helper="Allow Git LFS during the build process. A failed Git LFS pull fails the build. Disabled keeps the LFS pointer files."
            :options="[
                ['value' => true, 'label' => 'Enabled'],
                ['value' => false, 'label' => 'Disabled'],
            ]" />
        <x-forms.listbox id="isGitShallowCloneEnabled" label="Clone depth" onChange="saveGitOptions"
            canGate="update" :canResource="$resource"
            helper="Shallow cloning (--depth=1) speeds up deployments by only fetching the latest commit, useful for large repositories."
            :options="[
                ['value' => false, 'label' => 'Full history'],
                ['value' => true, 'label' => 'Shallow clone (latest commit only)'],
            ]" />
    </div>
</x-application.settings-subsection>
