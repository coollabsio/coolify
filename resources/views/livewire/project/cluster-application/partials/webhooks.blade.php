@if ($workload->buildsFromGit() && $workload->node_stack_id === null)
    <x-application.settings-subsection id="cluster-application-deployment-settings" title="Deployment"
        helper="Automatic deployments from Git webhooks." :first="true" class="mb-6">
        <div class="grid w-full gap-4 sm:grid-cols-2">
            <x-forms.listbox id="isAutoDeployEnabled" label="Auto deploy" onChange="saveDeploymentSettings"
                canGate="update" :canResource="$workload"
                helper="Automatically deploy new commits based on Git webhooks."
                :options="[
                    ['value' => true, 'label' => 'Deploy on push (webhooks)'],
                    ['value' => false, 'label' => 'Manual deployments only'],
                ]" />
            <x-forms.listbox id="isAutoCancelQueuedDeploymentsEnabled" label="Queued webhook deployments"
                onChange="saveDeploymentSettings" canGate="update" :canResource="$workload"
                helper="A push that arrives while a build runs waits until the build ends. When a newer push arrives, cancel the older webhook builds that still wait, so only the latest commit is built. Manual, API, and rollback deployments are never cancelled."
                :disabled="$isAutoCancelBuildingDeploymentsEnabled"
                :options="[
                    ['value' => true, 'label' => 'Cancel when a newer push arrives'],
                    ['value' => false, 'label' => 'Deploy every push'],
                ]" />
            <x-forms.listbox id="isAutoCancelBuildingDeploymentsEnabled" label="Building webhook deployments"
                onChange="saveDeploymentSettings" canGate="update" :canResource="$workload"
                helper="When a newer push arrives, also stop the older webhook build that is still building. A build that already deploys always finishes. This also cancels the waiting webhook builds."
                :options="[
                    ['value' => false, 'label' => 'Let the build finish'],
                    ['value' => true, 'label' => 'Stop the build when a newer push arrives'],
                ]" />
        </div>
    </x-application.settings-subsection>
@endif
<livewire:project.shared.webhooks :resource="$workload" :key="'cluster-application-webhooks-'.$workload->uuid" />
