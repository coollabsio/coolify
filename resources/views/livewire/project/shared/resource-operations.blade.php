<div>
    <h2>Resource Operations</h2>
    <div>You can easily make different kind of operations on this resource.</div>
    <h3>Clone</h3>
    <div class="pb-4">To another project / environment on a different / same server.</div>
    <div class="pb-4">
        <div class="flex flex-col flex-wrap gap-2">
            @foreach ($servers->sortBy('id') as $server)
                <h5>Server: <span class="font-bold text-dark dark:text-white">{{ $server->name }}</span></h5>
                @foreach ($server->destinations() as $destination)
                    <x-modal-confirmation title="Clone Resource?" buttonTitle="Clone Resource"
                        submitAction="cloneTo({{ data_get($destination, 'id') }})" :actions="[
                            'All containers of this resource will be duplicated and cloned to the selected destination.',
                        ]" :confirmWithText="false"
                        :confirmWithPassword="false" step2ButtonText="Clone Resource" dispatchEvent="true" dispatchEventType="success"
                        dispatchEventMessage="Resource cloned to {{ $destination->name }} destination.">
                        <x:slot name="content">
                            <div class="box group">
                                <div class="flex flex-col">
                                    <div class="box-title">Network</div>
                                    <div class="box-description">{{ $destination->name }}</div>
                                </div>
                            </div>
                        </x:slot>
                    </x-modal-confirmation>
                @endforeach
            @endforeach
        </div>
    </div>
    <h3>Move</h3>
    <div class="pb-4">Between projects / environments.</div>
    <div>
        <div class="pb-2">
            This resource is currently in the <span
                class="font-bold dark:text-warning">{{ $resource->environment->project->name }} /
                {{ $resource->environment->name }}</span> environment.
        </div>
        <div class="flex flex-col flex-wrap gap-2">
            @forelse ($projects as $project)
                <h5>Project: <span class="font-bold text-dark dark:text-white">{{ $project->name }}</span></h5>

                @foreach ($project->environments as $environment)
                    <x-modal-confirmation title="Move Resource?" buttonTitle="Move Resource"
                        submitAction="moveTo({{ data_get($environment, 'id') }})" :actions="['All containers of this resource will be moved to the selected environment.']" :confirmWithText="false"
                        :confirmWithPassword="false" step2ButtonText="Move Resource" dispatchEvent="true"
                        dispatchEventType="success"
                        dispatchEventMessage="Resource moved to {{ $environment->name }} environment.">
                        <x:slot:content>
                            <div class="box group">
                                <div class="flex flex-col">
                                    <div class="box-title">Environment</div>
                                    <div class="box-description">{{ $environment->name }}</div>
                                </div>
                            </div>
                            </x:slot>
                    </x-modal-confirmation>
                @endforeach
            @empty
                <div>No projects found to move to</div>
            @endforelse
        </div>
    </div>
</div>
