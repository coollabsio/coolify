<div>
    <x-slot:title>
        Create a new Application | {{ config('app.name') }}
    </x-slot>
    <livewire:project.header :project="$project" />
    
    <div class="pb-10">
        @if ($current_step === 'credentials')
            <h2 class="pb-4">Enter Git Credentials</h2>
            <div class="text-sm">Enter your HTTPS Git credentials to access your private repository. These credentials will be encrypted and stored securely.</div>
            
            <form wire:submit="next" class="flex flex-col gap-4 pt-6">
                <div class="flex flex-col gap-2">
                    <x-forms.input 
                        required 
                        id="git_username" 
                        label="Username" 
                        placeholder="Your Git username or personal access token name"
                        helper="For GitHub, use your username. For GitLab, use 'oauth2' or your username." />
                </div>
                
                <div class="flex flex-col gap-2">
                    <x-forms.input 
                        required 
                        type="password"
                        id="git_password" 
                        label="Password / Personal Access Token" 
                        placeholder="Your password or personal access token"
                        helper="For GitHub/GitLab, we recommend using a personal access token instead of your password." />
                </div>
                
                <div class="p-4 bg-warning rounded-md">
                    <h3 class="font-bold mb-2">Security Notes:</h3>
                    <ul class="list-disc list-inside text-sm space-y-1">
                        <li>Your credentials will be encrypted before storage</li>
                        <li>We recommend using personal access tokens instead of passwords</li>
                        <li>For GitHub: Create a token at Settings → Developer settings → Personal access tokens</li>
                        <li>For GitLab: Create a token at User Settings → Access Tokens</li>
                        <li>Ensure your token has repository read permissions</li>
                    </ul>
                </div>
                
                <div class="flex gap-2">
                    <x-forms.button type="submit">Continue</x-forms.button>
                    <x-forms.button 
                        type="button" 
                        wire:navigate 
                        href="{{ route('project.resource.create', ['project_uuid' => request()->route('project_uuid'), 'environment_uuid' => request()->route('environment_uuid')]) }}">
                        Cancel
                    </x-forms.button>
                </div>
            </form>
        @endif
        
        @if ($current_step === 'repository')
            <h2 class="pb-4">Configure Repository</h2>
            
            <form wire:submit="submit" class="flex flex-col gap-4">
                <div class="flex flex-col gap-2">
                    <div class="flex gap-2">
                        <div class="flex-1">
                            <x-forms.input 
                                required 
                                placeholder="https://github.com/username/repository" 
                                id="git_repository" 
                                label="Repository URL (HTTPS only)"
                                helper="Must be an HTTPS URL (e.g., https://github.com/user/repo.git)" />
                        </div>
                        <div class="flex-1">
                            <x-forms.input 
                                required 
                                placeholder="main" 
                                id="git_branch" 
                                label="Branch" />
                        </div>
                    </div>
                </div>
                
                <div class="flex flex-col gap-2">
                    <div class="flex gap-2">
                        <div class="flex-1">
                            <x-forms.select 
                                required 
                                id="build_pack" 
                                label="Build Pack">
                                <option value="nixpacks">Nixpacks</option>
                                <option value="static">Static</option>
                                <option value="dockerfile">Dockerfile</option>
                                <option value="dockercompose">Docker Compose</option>
                            </x-forms.select>
                        </div>
                        @if ($build_pack !== 'static' && $build_pack !== 'dockercompose')
                            <div class="flex-1">
                                <x-forms.input 
                                    required 
                                    placeholder="3000" 
                                    id="port" 
                                    label="Port" />
                            </div>
                        @endif
                    </div>
                </div>
                
                @if ($build_pack === 'static')
                    <div class="flex flex-col gap-2">
                        <x-forms.checkbox instantSave id="is_static" label="Is it a static site?" />
                        @if ($is_static)
                            <x-forms.input 
                                placeholder="/dist" 
                                id="publish_directory" 
                                label="Publish Directory"
                                helper="The directory where your static files are located after build." />
                        @endif
                    </div>
                @endif
                
                @if ($build_pack === 'dockerfile')
                    <div class="flex flex-col gap-2">
                        <x-forms.input 
                            id="dockerfile" 
                            label="Dockerfile Location" 
                            helper="Do not include / in front of it."
                            placeholder="Dockerfile" />
                    </div>
                @endif
                
                @if ($build_pack === 'dockercompose')
                    <div class="flex flex-col gap-2">
                        <x-forms.input 
                            placeholder="docker-compose.yaml" 
                            id="docker_compose_location" 
                            label="Docker Compose Location"
                            helper="Do not include / in front of it." />
                        <x-forms.input 
                            placeholder="docker compose up -d" 
                            id="docker_compose_custom_start_command"
                            label="Docker Compose Custom Start Command" />
                        <x-forms.input 
                            placeholder="docker compose build" 
                            id="docker_compose_custom_build_command"
                            label="Docker Compose Custom Build Command" />
                    </div>
                @endif
                
                <div class="flex flex-col gap-2">
                    <x-forms.input 
                        id="base_directory" 
                        label="Base Directory"
                        placeholder="/"
                        helper="Directory to use as the base for all commands. Useful for monorepos." />
                </div>
                
                <div class="flex gap-2">
                    <x-forms.button type="submit">Create Application</x-forms.button>
                    <x-forms.button 
                        type="button" 
                        wire:click="$set('current_step', 'credentials')">
                        Back
                    </x-forms.button>
                </div>
            </form>
        @endif
    </div>
</div>