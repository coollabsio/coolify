@php use App\Enums\ProxyTypes; @endphp
<div>
    @if ($currentState === 'welcome')
        <h1 class="text-5xl font-bold">Welcome to Coolify</h1>
        <p class="py-6 text-xl text-center">Let me help you to set the basics.</p>
        <div class="flex justify-center ">
            <div class="justify-center box" wire:click="$set('currentState', 'select-server')">Get Started
            </div>
        </div>
    @endif
    @if ($currentState === 'select-server')
        <x-boarding-step title="Server">
            <x-slot:question>
                Do you want to deploy your resources on your <x-highlighted text="Localhost" />
                or on a <x-highlighted text="Remote Server" />?
            </x-slot:question>
            <x-slot:actions>
                <div class="justify-center box" wire:click="setServer('localhost')">Localhost
                </div>
                <div class="justify-center box" wire:click="setServer('remote')">Remote Server
                </div>
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
    @if ($currentState === 'private-key')
        <x-boarding-step title="SSH Key">
            <x-slot:question>
                Do you have your own SSH Private Key?
            </x-slot:question>
            <x-slot:actions>
                <div class="justify-center box" wire:click="setPrivateKey('own')">Yes
                </div>
                <div class="justify-center box" wire:click="setPrivateKey('create')">No (create one for me)
                </div>
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
    @if ($currentState === 'create-private-key')
        <x-boarding-step title="Create Private Key">
            <x-slot:question>
                Please let me know your key details.
            </x-slot:question>
            <x-slot:actions>
                <form wire:submit.prevent='savePrivateKey' class="flex flex-col w-full gap-4 pr-10">
                    <x-forms.input required placeholder="Choose a name for your Private Key. Could be anything."
                        label="Name" id="privateKeyName" />
                    <x-forms.input placeholder="Description, so others will know more about this." label="Description"
                        id="privateKeyDescription" />
                    <x-forms.textarea required placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" label="Private Key"
                        id="privateKey" />
                    @if ($privateKeyType === 'create' && !isDev())
                        <span class="font-bold text-warning">Copy this to your server's ~/.ssh/authorized_keys
                            file.</span>
                        <x-forms.textarea rows="7" readonly label="Public Key" id="publicKey" />
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
                        <x-forms.input required placeholder="Port number of your server. Default is 22." label="Port"
                            id="remoteServerPort" />
                        <x-forms.input required readonly
                            placeholder="Username to connect to your server. Default is root." label="Username"
                            id="remoteServerUser" />
                    </div>
                    <x-forms.button type="submit">Save</x-forms.button>
                </form>
            </x-slot:actions>
            <x-slot:explanation>
                <p>Username should be <x-highlighted text="root" /> for now. We are working on to use
                    non-root users.</p>
            </x-slot:explanation>
        </x-boarding-step>
    @endif
    @if ($currentState === 'install-docker')
        <x-boarding-step title="Install Docker">
            <x-slot:question>
                Could not find Docker Engine on your server. Do you want me to install it for you?
            </x-slot:question>
            <x-slot:actions>
                <div class="justify-center box" wire:click="installDocker" onclick="installDocker.showModal()">
                    Let's do
                    it!</div>
            </x-slot:actions>
            <x-slot:explanation>
                <p>This will install the latest Docker Engine on your server, configure a few things to be able
                    to run optimal.</p>
            </x-slot:explanation>
        </x-boarding-step>
    @endif
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
    @if ($currentState === 'create-project')
        <x-boarding-step title="Project">
            <x-slot:question>
                I will create an initial project for you. You can change all the details later on.
            </x-slot:question>
            <x-slot:actions>
                <div class="justify-center box" wire:click="createNewProject">Let's do it!</div>
            </x-slot:actions>
            <x-slot:explanation>
                <p>Projects are bound together several resources into one virtual group. There are no
                    limitations on the number of projects you could have.</p>
                <p>Each project should have at least one environment. This helps you to create a production &
                    staging version of the same application, but grouped separately.</p>
            </x-slot:explanation>
        </x-boarding-step>
    @endif
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
    <div class="flex justify-center gap-2 pt-4">
        <a wire:click='skipBoarding'>Skip boarding process</a>
        <a wire:click='restartBoarding'>Restart boarding process</a>
    </div>
</div>
