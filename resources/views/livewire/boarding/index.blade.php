@php use App\Enums\ProxyTypes; @endphp
<div>
    <div>
        @if ($currentState === 'welcome')
            <h1 class="text-5xl font-bold">Welcome to Coolify</h1>
            <p class="py-6 text-xl text-center">Let me help you to set the basics.</p>
            <div class="flex justify-center ">
                <x-forms.button class="justify-center box" wire:click="$set('currentState','explanation')">Get Started
                </x-forms.button>
            </div>
        @endif
    </div>
    <div>
        @if ($currentState === 'explanation')
            <x-boarding-step title="What is Coolify?">
                <x-slot:question>
                    Coolify is an all-in-one application to automate tasks on your servers, deploy application with Git
                    integrations, deploy databases and services, monitor these resources with notifications and alerts
                    without vendor lock-in
                    and <a href="https://coolify.io" class="text-white hover:underline">much much more</a>.
                    <br><br>
                    <span class="text-xl">
                        <x-highlighted text="Self-hosting with superpowers!" /></span>
                </x-slot:question>
                <x-slot:explanation>
                    <p><x-highlighted text="Task automation:" /> You do not to manage your servers too much. Coolify do it for you.</p>
                    <p><x-highlighted text="No vendor lock-in:" /> All configurations are stored on your server, so everything works without Coolify (except integrations and automations).</p>
                    <p><x-highlighted text="Monitoring:" />You will get notified on your favourite platform (Discord, Telegram, Email, etc.) when something goes wrong, or an action needed from your side.</p>
                </x-slot:explanation>
                <x-slot:actions>
                    <x-forms.button class="justify-center box" wire:click="explanation">Next
                    </x-forms.button>
                </x-slot:actions>
            </x-boarding-step>
        @endif
        @if ($currentState === 'select-server-type')
            <x-boarding-step title="Server">
                <x-slot:question>
                    Do you want to deploy your resources on your <x-highlighted text="Localhost" />
                    or on a <x-highlighted text="Remote Server" />?
                </x-slot:question>
                <x-slot:actions>
                    <x-forms.button class="justify-center box" wire:target="setServerType('localhost')"
                        wire:click="setServerType('localhost')">Localhost
                    </x-forms.button>
                    <x-forms.button class="justify-center box" wire:target="setServerType('remote')"
                        wire:click="setServerType('remote')">Remote Server
                    </x-forms.button>
                </x-slot:actions>
                <x-slot:explanation>
                    <p>Servers are the main building blocks, as they will host your applications, databases,
                        services, called resources. Any CPU intensive process will use the server's CPU where you
                        are deploying your resources.</p>
                    <p>Localhost is the server where Coolify is running on. It is not recommended to use one server
                        for everyting.</p>
                    <p>Remote Server is a server reachable through SSH. It can be hosted at home, or from any cloud
                        provider.</p>
                </x-slot:explanation>
            </x-boarding-step>
        @endif
    </div>
    <div>
        @if ($currentState === 'private-key')
            <x-boarding-step title="SSH Key">
                <x-slot:question>
                    Do you have your own SSH Private Key?
                </x-slot:question>
                <x-slot:actions>
                    <x-forms.button class="justify-center box" wire:target="setPrivateKey('own')"
                        wire:click="setPrivateKey('own')">Yes
                    </x-forms.button>
                    <x-forms.button class="justify-center box" wire:target="setPrivateKey('create')"
                        wire:click="setPrivateKey('create')">No (create one for me)
                    </x-forms.button>
                    @if (count($privateKeys) > 0)
                        <form wire:submit.prevent='selectExistingPrivateKey' class="flex flex-col w-full gap-4 pr-10">
                            <x-forms.select label="Existing SSH Keys" id='selectedExistingPrivateKey'>
                                @foreach ($privateKeys as $privateKey)
                                    <option wire:key="{{ $loop->index }}" value="{{ $privateKey->id }}">
                                        {{ $privateKey->name }}</option>
                                @endforeach
                            </x-forms.select>
                            <x-forms.button type="submit">Use this SSH Key</x-forms.button>
                        </form>
                    @endif
                </x-slot:actions>
                <x-slot:explanation>
                    <p>SSH Keys are used to connect to a remote server through a secure shell, called SSH.</p>
                    <p>You can use your own ssh private key, or you can let Coolify to create one for you.</p>
                    <p>In both ways, you need to add the public version of your ssh private key to the remote
                        server's
                        <code class="text-warning">~/.ssh/authorized_keys</code> file.
                    </p>
                </x-slot:explanation>
            </x-boarding-step>
        @endif
    </div>
    <div>
        @if ($currentState === 'select-existing-server')
            <x-boarding-step title="Select a server">
                <x-slot:question>
                    There are already servers available for your Team. Do you want to use one of them?
                </x-slot:question>
                <x-slot:actions>
                    <x-forms.button class="justify-center box" wire:click="createNewServer">No (create one for me)
                    </x-forms.button>
                    <div>
                        <form wire:submit.prevent='selectExistingServer' class="flex flex-col w-full gap-4 lg:w-96">
                            <x-forms.select label="Existing servers" class="w-96" id='selectedExistingServer'>
                                @foreach ($servers as $server)
                                    <option wire:key="{{ $loop->index }}" value="{{ $server->id }}">
                                        {{ $server->name }}</option>
                                @endforeach
                            </x-forms.select>
                            <x-forms.button type="submit">Use this Server</x-forms.button>
                        </form>
                    </div>
                </x-slot:actions>
                <x-slot:explanation>
                    <p>Private Keys are used to connect to a remote server through a secure shell, called SSH.</p>
                    <p>You can use your own private key, or you can let Coolify to create one for you.</p>
                    <p>In both ways, you need to add the public version of your private key to the remote server's
                        <code>~/.ssh/authorized_keys</code> file.
                    </p>
                </x-slot:explanation>
            </x-boarding-step>
        @endif
    </div>
    <div>
        @if ($currentState === 'create-private-key')
            <x-boarding-step title="Create Private Key">
                <x-slot:question>
                    Please let me know your key details.
                </x-slot:question>
                <x-slot:actions>
                    <form wire:submit.prevent='savePrivateKey' class="flex flex-col w-full gap-4 pr-10">
                        <x-forms.input required placeholder="Choose a name for your Private Key. Could be anything."
                            label="Name" id="privateKeyName" />
                        <x-forms.input placeholder="Description, so others will know more about this."
                            label="Description" id="privateKeyDescription" />
                        <x-forms.textarea required placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" label="Private Key"
                            id="privateKey" />
                        @if ($privateKeyType === 'create')
                            <x-forms.textarea rows="7" readonly label="Public Key" id="publicKey" />
                            <span class="font-bold text-warning">ACTION REQUIRED: Copy the 'Public Key' to your server's
                                ~/.ssh/authorized_keys
                                file.</span>
                        @endif
                        <x-forms.button type="submit">Save</x-forms.button>
                    </form>
                </x-slot:actions>
                <x-slot:explanation>
                    <p>Private Keys are used to connect to a remote server through a secure shell, called SSH.</p>
                    <p>You can use your own private key, or you can let Coolify to create one for you.</p>
                    <p>In both ways, you need to add the public version of your private key to the remote server's
                        <code>~/.ssh/authorized_keys</code> file.
                    </p>
                </x-slot:explanation>
            </x-boarding-step>
        @endif
    </div>
    <div>
        @if ($currentState === 'create-server')
            <x-boarding-step title="Create Server">
                <x-slot:question>
                    Please let me know your server details.
                </x-slot:question>
                <x-slot:actions>
                    <form wire:submit.prevent='saveServer' class="flex flex-col w-full gap-4 pr-10">
                        <div class="flex gap-2">
                            <x-forms.input required placeholder="Choose a name for your Server. Could be anything."
                                label="Name" id="remoteServerName" />
                            <x-forms.input placeholder="Description, so others will know more about this."
                                label="Description" id="remoteServerDescription" />
                        </div>
                        <div class="flex gap-2">
                            <x-forms.input required placeholder="Hostname or IP address" label="Hostname or IP Address"
                                id="remoteServerHost" />
                            <x-forms.input required placeholder="Port number of your server. Default is 22."
                                label="Port" id="remoteServerPort" />
                            <x-forms.input required readonly
                                placeholder="Username to connect to your server. Default is root." label="Username"
                                id="remoteServerUser" />
                        </div>
                        <x-forms.button type="submit">Check Connection</x-forms.button>
                    </form>
                </x-slot:actions>
                <x-slot:explanation>
                    <p>Username should be <x-highlighted text="root" /> for now. We are working on to use
                        non-root users.</p>
                </x-slot:explanation>
            </x-boarding-step>
        @endif
    </div>
    <div>
        @if ($currentState === 'install-docker')
            <x-modal modalId="installDocker">
                <x-slot:modalBody>
                    <livewire:activity-monitor header="Docker Installation Logs" />
                </x-slot:modalBody>
                <x-slot:modalSubmit>
                    <x-forms.button onclick="installDocker.close()" type="submit">
                        Close
                    </x-forms.button>
                </x-slot:modalSubmit>
            </x-modal>
            <x-boarding-step title="Install Docker">
                <x-slot:question>
                    Could not find Docker Engine on your server. Do you want me to install it for you?
                </x-slot:question>
                <x-slot:actions>
                    <x-forms.button class="justify-center box" wire:click="installDocker"
                        onclick="installDocker.showModal()">
                        Let's do
                        it!</x-forms.button>
                </x-slot:actions>
                <x-slot:explanation>
                    <p>This will install the latest Docker Engine on your server, configure a few things to be able
                        to run optimal.</p>
                </x-slot:explanation>
            </x-boarding-step>
        @endif
    </div>
    <div>
        @if ($currentState === 'select-proxy')
            <x-boarding-step title="Select a Proxy">
                <x-slot:question>
                    If you would like to attach any kind of domain to your resources, you need a proxy.
                </x-slot:question>
                <x-slot:actions>
                    <x-forms.button wire:click="selectProxy" class="w-64 box">
                        Decide later
                    </x-forms.button>
                    <x-forms.button class="w-32 box" wire:click="selectProxy('{{ ProxyTypes::TRAEFIK_V2 }}')">
                        Traefik
                        v2
                    </x-forms.button>
                    <x-forms.button disabled class="w-32 box">
                        Nginx
                    </x-forms.button>
                    <x-forms.button disabled class="w-32 box">
                        Caddy
                    </x-forms.button>
                </x-slot:actions>
                <x-slot:explanation>
                    <p>This will install the latest Docker Engine on your server, configure a few things to be able
                        to run optimal.</p>
                </x-slot:explanation>
            </x-boarding-step>
        @endif
    </div>
    <div>
        @if ($currentState === 'create-project')
            <x-boarding-step title="Project">
                <x-slot:question>
                    @if (count($projects) > 0)
                        You already have some projects. Do you want to use one of them or should I create a new one for
                        you?
                    @else
                        I will create an initial project for you. You can change all the details later on.
                    @endif
                </x-slot:question>
                <x-slot:actions>
                    <x-forms.button class="justify-center box" wire:click="createNewProject">Let's create a new
                        one!</x-forms.button>
                    <div>
                        @if (count($projects) > 0)
                            <form wire:submit.prevent='selectExistingProject'
                                class="flex flex-col w-full gap-4 lg:w-96">
                                <x-forms.select label="Existing projects" class="w-96"
                                    id='selectedExistingProject'>
                                    @foreach ($projects as $project)
                                        <option wire:key="{{ $loop->index }}" value="{{ $project->id }}">
                                            {{ $project->name }}</option>
                                    @endforeach
                                </x-forms.select>
                                <x-forms.button type="submit">Use this Project</x-forms.button>
                            </form>
                        @endif
                    </div>
                </x-slot:actions>
                <x-slot:explanation>
                    <p>Projects are bound together several resources into one virtual group. There are no
                        limitations on the number of projects you could have.</p>
                    <p>Each project should have at least one environment. This helps you to create a production &
                        staging version of the same application, but grouped separately.</p>
                </x-slot:explanation>
            </x-boarding-step>
        @endif
    </div>
    <div>
        @if ($currentState === 'create-resource')
            <x-boarding-step title="Resources">
                <x-slot:question>
                    I will redirect you to the new resource page, where you can create your first resource.
                </x-slot:question>
                <x-slot:actions>
                    <div class="justify-center box" wire:click="showNewResource">Let's do
                        it!</div>
                </x-slot:actions>
                <x-slot:explanation>
                    <p>A resource could be an application, a database or a service (like WordPress).</p>
                </x-slot:explanation>
            </x-boarding-step>
        @endif
    </div>
    <div class="flex justify-center gap-2 pt-4">
        <a wire:click='skipBoarding'>Skip boarding process</a>
        <a wire:click='restartBoarding'>Restart boarding process</a>
    </div>
</div>
