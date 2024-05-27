<nav class="flex flex-col flex-1 bg-white border-r dark:border-coolgray-200 dark:bg-base" x-data="{
    switchWidth() {
            if (this.full === 'full') {
                localStorage.removeItem('pageWidth');
            } else {
                localStorage.setItem('pageWidth', 'full');
            }
            window.location.reload();
        },
        init() {
            this.full = localStorage.getItem('pageWidth');
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
                const userSettings = localStorage.getItem('theme');
                if (userSettings !== 'system') {
                    return;
                }
                if (e.matches) {
                    document.documentElement.classList.add('dark');
                } else {
                    document.documentElement.classList.remove('dark');
                }
            });
            this.queryTheme();
        },
        setTheme(type) {
            this.theme = type;
            localStorage.setItem('theme', type);
            this.queryTheme();
        },
        queryTheme() {
            const darkModePreference = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const userSettings = localStorage.getItem('theme') || 'dark';
            localStorage.setItem('theme', userSettings);
            if (userSettings === 'dark') {
                document.documentElement.classList.add('dark');
                this.theme = 'dark';
            } else if (userSettings === 'light') {
                document.documentElement.classList.remove('dark');
                this.theme = 'light';
            } else if (darkModePreference) {
                this.theme = 'system';
                document.documentElement.classList.add('dark');
            } else if (!darkModePreference) {
                this.theme = 'system';
                document.documentElement.classList.remove('dark');
            }
        }
}">
    <div class="flex pt-6 pb-4 pl-3">
        <div class="flex flex-col w-full">
            <div class="text-2xl font-bold tracking-wide dark:text-white">Coolify</div>
            <x-version />
        </div>
        <div class="pt-1">
            <x-dropdown>
                <x-slot:title>
                    <div class="flex justify-end w-8" x-show="theme === 'dark' || theme === 'system'">
                        <svg class="w-5 h-5 rounded dark:fill-white" xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 24 24">
                            <path
                                d="M5.64,17l-.71.71a1,1,0,0,0,0,1.41,1,1,0,0,0,1.41,0l.71-.71A1,1,0,0,0,5.64,17ZM5,12a1,1,0,0,0-1-1H3a1,1,0,0,0,0,2H4A1,1,0,0,0,5,12Zm7-7a1,1,0,0,0,1-1V3a1,1,0,0,0-2,0V4A1,1,0,0,0,12,5ZM5.64,7.05a1,1,0,0,0,.7.29,1,1,0,0,0,.71-.29,1,1,0,0,0,0-1.41l-.71-.71A1,1,0,0,0,4.93,6.34Zm12,.29a1,1,0,0,0,.7-.29l.71-.71a1,1,0,1,0-1.41-1.41L17,5.64a1,1,0,0,0,0,1.41A1,1,0,0,0,17.66,7.34ZM21,11H20a1,1,0,0,0,0,2h1a1,1,0,0,0,0-2Zm-9,8a1,1,0,0,0-1,1v1a1,1,0,0,0,2,0V20A1,1,0,0,0,12,19ZM18.36,17A1,1,0,0,0,17,18.36l.71.71a1,1,0,0,0,1.41,0,1,1,0,0,0,0-1.41ZM12,6.5A5.5,5.5,0,1,0,17.5,12,5.51,5.51,0,0,0,12,6.5Zm0,9A3.5,3.5,0,1,1,15.5,12,3.5,3.5,0,0,1,12,15.5Z" />
                        </svg>
                    </div>
                    <div class="flex justify-end w-8" x-show="theme === 'light'">
                        <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                            <path
                                d="M21.64,13a1,1,0,0,0-1.05-.14,8.05,8.05,0,0,1-3.37.73A8.15,8.15,0,0,1,9.08,5.49a8.59,8.59,0,0,1,.25-2A1,1,0,0,0,8,2.36,10.14,10.14,0,1,0,22,14.05,1,1,0,0,0,21.64,13Zm-9.5,6.69A8.14,8.14,0,0,1,7.08,5.22v.27A10.15,10.15,0,0,0,17.22,15.63a9.79,9.79,0,0,0,2.1-.22A8.11,8.11,0,0,1,12.14,19.73Z" />
                        </svg>
                    </div>
                </x-slot:title>
                <div class="flex flex-col gap-1">
                    <div class="mb-1 font-bold border-b dark:border-coolgray-500 dark:text-white text-md">Color</div>
                    <button @click="setTheme('dark')" class="px-1 dropdown-item-no-padding">Dark</button>
                    <button @click="setTheme('light')" class="px-1 dropdown-item-no-padding">Light</button>
                    <button @click="setTheme('system')" class="px-1 dropdown-item-no-padding">System</button>
                    <div class="my-1 font-bold border-b dark:border-coolgray-500 dark:text-white text-md">Width</div>
                    <button @click="switchWidth()" class="px-1 dropdown-item-no-padding" x-show="full">Center</button>
                    <button @click="switchWidth()" class="px-1 dropdown-item-no-padding" x-show="!full">Full</button>
                </div>
            </x-dropdown>
        </div>
    </div>
    <div class="px-2 pt-2 pb-7">
        <livewire:switch-team />
    </div>
    <ul role="list" class="flex flex-col flex-1 gap-y-7">
        <li class="flex-1 overflow-x-hidden">
            <ul role="list" class="flex flex-col h-full space-y-1.5">
                @if (isSubscribed() || !isCloud())
                    <li>
                        <a title="Dashboard" href="/"
                            class="{{ request()->is('/') ? 'menu-item-active menu-item' : 'menu-item' }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                            </svg>
                            Dashboard
                        </a>
                    </li>
                    <li>
                        <a title="Projects"
                            class="{{ request()->is('project/*') || request()->is('projects') ? 'menu-item menu-item-active' : 'menu-item' }}"
                            href="/projects">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path d="M12 4l-8 4l8 4l8 -4l-8 -4" />
                                <path d="M4 12l8 4l8 -4" />
                                <path d="M4 16l8 4l8 -4" />
                            </svg>
                            Projects
                        </a>
                    </li>
                    <li>
                        <a title="Servers"
                            class="{{ request()->is('server/*') || request()->is('servers') ? 'menu-item menu-item-active' : 'menu-item' }}"
                            href="/servers">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path
                                    d="M3 4m0 3a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v2a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3z" />
                                <path d="M15 20h-9a3 3 0 0 1 -3 -3v-2a3 3 0 0 1 3 -3h12" />
                                <path d="M7 8v.01" />
                                <path d="M7 16v.01" />
                                <path d="M20 15l-2 3h3l-2 3" />
                            </svg>
                            Servers
                        </a>
                    </li>

                    <li>
                        <a title="Sources"
                            class="{{ request()->is('source*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                            href="{{ route('source.all') }}">
                            <svg class="icon" viewBox="0 0 15 15" xmlns="http://www.w3.org/2000/svg">
                                <path fill="currentColor"
                                    d="m6.793 1.207l.353.354l-.353-.354ZM1.207 6.793l-.353-.354l.353.354Zm0 1.414l.354-.353l-.354.353Zm5.586 5.586l-.354.353l.354-.353Zm1.414 0l-.353-.354l.353.354Zm5.586-5.586l.353.354l-.353-.354Zm0-1.414l-.354.353l.354-.353ZM8.207 1.207l.354-.353l-.354.353ZM6.44.854L.854 6.439l.707.707l5.585-5.585L6.44.854ZM.854 8.56l5.585 5.585l.707-.707l-5.585-5.585l-.707.707Zm7.707 5.585l5.585-5.585l-.707-.707l-5.585 5.585l.707.707Zm5.585-7.707L8.561.854l-.707.707l5.585 5.585l.707-.707Zm0 2.122a1.5 1.5 0 0 0 0-2.122l-.707.707a.5.5 0 0 1 0 .708l.707.707ZM6.44 14.146a1.5 1.5 0 0 0 2.122 0l-.707-.707a.5.5 0 0 1-.708 0l-.707.707ZM.854 6.44a1.5 1.5 0 0 0 0 2.122l.707-.707a.5.5 0 0 1 0-.708L.854 6.44Zm6.292-4.878a.5.5 0 0 1 .708 0L8.56.854a1.5 1.5 0 0 0-2.122 0l.707.707Zm-2 1.293l1 1l.708-.708l-1-1l-.708.708ZM7.5 5a.5.5 0 0 1-.5-.5H6A1.5 1.5 0 0 0 7.5 6V5Zm.5-.5a.5.5 0 0 1-.5.5v1A1.5 1.5 0 0 0 9 4.5H8ZM7.5 4a.5.5 0 0 1 .5.5h1A1.5 1.5 0 0 0 7.5 3v1Zm0-1A1.5 1.5 0 0 0 6 4.5h1a.5.5 0 0 1 .5-.5V3Zm.646 2.854l1.5 1.5l.707-.708l-1.5-1.5l-.707.708ZM10.5 8a.5.5 0 0 1-.5-.5H9A1.5 1.5 0 0 0 10.5 9V8Zm.5-.5a.5.5 0 0 1-.5.5v1A1.5 1.5 0 0 0 12 7.5h-1Zm-.5-.5a.5.5 0 0 1 .5.5h1A1.5 1.5 0 0 0 10.5 6v1Zm0-1A1.5 1.5 0 0 0 9 7.5h1a.5.5 0 0 1 .5-.5V6ZM7 5.5v4h1v-4H7Zm.5 5.5a.5.5 0 0 1-.5-.5H6A1.5 1.5 0 0 0 7.5 12v-1Zm.5-.5a.5.5 0 0 1-.5.5v1A1.5 1.5 0 0 0 9 10.5H8Zm-.5-.5a.5.5 0 0 1 .5.5h1A1.5 1.5 0 0 0 7.5 9v1Zm0-1A1.5 1.5 0 0 0 6 10.5h1a.5.5 0 0 1 .5-.5V9Z" />
                            </svg>
                            Sources
                        </a>
                    </li>
                    <li>
                        <a title="Destinations"
                            class="{{ request()->is('destination*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                            href="{{ route('destination.all') }}">

                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24">
                                <path fill="none" stroke="currentColor" stroke-linecap="round"
                                    stroke-linejoin="round" stroke-width="2"
                                    d="M9 4L3 8v12l6-3l6 3l6-4V4l-6 3l-6-3zm-2 8.001V12m4 .001V12m3-2l2 2m2 2l-2-2m0 0l2-2m-2 2l-2 2" />
                            </svg>
                            Destinations
                        </a>
                    </li>
                    <li>
                        <a title="S3 Storages"
                            class="{{ request()->is('storages*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                            href="{{ route('storage.index') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24">
                                <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                    stroke-width="2">
                                    <path d="M4 6a8 3 0 1 0 16 0A8 3 0 1 0 4 6" />
                                    <path d="M4 6v6a8 3 0 0 0 16 0V6" />
                                    <path d="M4 12v6a8 3 0 0 0 16 0v-6" />
                                </g>
                            </svg>
                            S3 Storages
                        </a>
                    </li>
                    <li>
                        <a title="Shared variables"
                            class="{{ request()->is('shared-variables*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                            href="{{ route('shared-variables.index') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24">
                                <g fill="none" stroke="currentColor" stroke-linecap="round"
                                    stroke-linejoin="round" stroke-width="2">
                                    <path
                                        d="M5 4C2.5 9 2.5 14 5 20M19 4c2.5 5 2.5 10 0 16M9 9h1c1 0 1 1 2.016 3.527C13 15 13 16 14 16h1" />
                                    <path d="M8 16c1.5 0 3-2 4-3.5S14.5 9 16 9" />
                                </g>
                            </svg>
                            Shared Variables
                        </a>
                    </li>
                    <li>
                        <a title="Notifications"
                            class="{{ request()->is('notifications*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                            href="{{ route('notifications.email') }}">
                            <svg class="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                                <path fill="none" stroke="currentColor" stroke-linecap="round"
                                    stroke-linejoin="round" stroke-width="2"
                                    d="M10 5a2 2 0 1 1 4 0a7 7 0 0 1 4 6v3a4 4 0 0 0 2 3H4a4 4 0 0 0 2-3v-3a7 7 0 0 1 4-6M9 17v1a3 3 0 0 0 6 0v-1" />
                            </svg>
                            Notifications
                        </a>
                    </li>
                    <li>
                        <a title="Keys & Tokens"
                            class="{{ request()->is('security*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                            href="{{ route('security.private-key.index') }}">
                            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path fill="none" stroke="currentColor" stroke-linecap="round"
                                    stroke-linejoin="round" stroke-width="2"
                                    d="m16.555 3.843l3.602 3.602a2.877 2.877 0 0 1 0 4.069l-2.643 2.643a2.877 2.877 0 0 1-4.069 0l-.301-.301l-6.558 6.558a2 2 0 0 1-1.239.578L5.172 21H4a1 1 0 0 1-.993-.883L3 20v-1.172a2 2 0 0 1 .467-1.284l.119-.13L4 17h2v-2h2v-2l2.144-2.144l-.301-.301a2.877 2.877 0 0 1 0-4.069l2.643-2.643a2.877 2.877 0 0 1 4.069 0zM15 9h.01" />
                            </svg>
                            Keys & Tokens
                        </a>
                    </li>
                    <li>
                        <a title="Tags"
                            class="{{ request()->is('tags*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                            href="{{ route('tags.index') }}">
                            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <g fill="none" stroke="currentColor" stroke-linecap="round"
                                    stroke-linejoin="round" stroke-width="2">
                                    <path
                                        d="M3 8v4.172a2 2 0 0 0 .586 1.414l5.71 5.71a2.41 2.41 0 0 0 3.408 0l3.592-3.592a2.41 2.41 0 0 0 0-3.408l-5.71-5.71A2 2 0 0 0 9.172 6H5a2 2 0 0 0-2 2" />
                                    <path d="m18 19l1.592-1.592a4.82 4.82 0 0 0 0-6.816L15 6m-8 4h-.01" />
                                </g>
                            </svg>
                            Tags
                        </a>
                    </li>
                    <li>
                        <a title="Command Center"
                            class="{{ request()->is('command-center*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                            href="{{ route('command-center') }}">
                            <svg class="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path d="M5 7l5 5l-5 5" />
                                <path d="M12 19l7 0" />
                            </svg>
                            Command Center
                        </a>
                    </li>
                    <li>
                        <a title="Profile"
                            class="{{ request()->is('profile*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                            href="{{ route('profile') }}">
                            <svg class="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" />
                                <path d="M12 10m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" />
                                <path d="M6.168 18.849a4 4 0 0 1 3.832 -2.849h4a4 4 0 0 1 3.834 2.855" />
                            </svg>
                            Profile
                        </a>
                    </li>
                    <li>
                        <a title="Teams"
                            class="{{ request()->is('team*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                            href="{{ route('team.index') }}">
                            <svg class="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path d="M10 13a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                                <path d="M8 21v-1a2 2 0 0 1 2 -2h4a2 2 0 0 1 2 2v1" />
                                <path d="M15 5a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                                <path d="M17 10h2a2 2 0 0 1 2 2v1" />
                                <path d="M5 5a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                                <path d="M3 13v-1a2 2 0 0 1 2 -2h2" />
                            </svg>
                            Teams
                        </a>
                    </li>
                    @if (isCloud())
                        <li>
                            <a title="Subscription"
                                class="{{ request()->is('subscription*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                                href="{{ route('subscription.show') }}">
                                <svg class="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                                    <path fill="none" stroke="currentColor" stroke-linecap="round"
                                        stroke-linejoin="round" stroke-width="2"
                                        d="M3 8a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3v8a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3zm0 2h18M7 15h.01M11 15h2" />
                                </svg>
                                Subscription
                            </a>
                        </li>
                    @endif
                    @if (isInstanceAdmin())
                        <li>

                            <a title="Settings"
                                class="{{ request()->is('settings*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                                href="/settings">
                                <svg class="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                    stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                    <path
                                        d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z" />
                                    <path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0" />
                                </svg>
                                Settings
                            </a>
                        </li>
                    @endif

                    @if (isCloud() && isInstanceAdmin())
                        <li>
                            <a title="Admin" class="menu-item" href="/admin">
                                <svg class="text-pink-600 icon" viewBox="0 0 256 256"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path fill="currentColor"
                                        d="M177.62 159.6a52 52 0 0 1-34 34a12.2 12.2 0 0 1-3.6.55a12 12 0 0 1-3.6-23.45a28 28 0 0 0 18.32-18.32a12 12 0 0 1 22.9 7.2ZM220 144a92 92 0 0 1-184 0c0-28.81 11.27-58.18 33.48-87.28a12 12 0 0 1 17.9-1.33l19.69 19.11L127 19.89a12 12 0 0 1 18.94-5.12C168.2 33.25 220 82.85 220 144m-24 0c0-41.71-30.61-78.39-52.52-99.29l-20.21 55.4a12 12 0 0 1-19.63 4.5L80.71 82.36C67 103.38 60 124.06 60 144a68 68 0 0 0 136 0" />
                                </svg>
                                Admin
                            </a>
                        </li>
                    @endif
                    <div class="flex-1"></div>
                    @if (isInstanceAdmin() && !isCloud())
                        @persist('upgrade')
                            <li>
                                <livewire:upgrade />
                            </li>
                        @endpersist
                    @endif
                    <li>
                        <a title="Onboarding"
                            class="{{ request()->is('onboarding*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                            href="{{ route('onboarding') }}">
                            <svg class="icon" viewBox="0 0 256 256" xmlns="http://www.w3.org/2000/svg">
                                <path fill="currentColor"
                                    d="M224 128a8 8 0 0 1-8 8h-88a8 8 0 0 1 0-16h88a8 8 0 0 1 8 8m-96-56h88a8 8 0 0 0 0-16h-88a8 8 0 0 0 0 16m88 112h-88a8 8 0 0 0 0 16h88a8 8 0 0 0 0-16M82.34 42.34L56 68.69L45.66 58.34a8 8 0 0 0-11.32 11.32l16 16a8 8 0 0 0 11.32 0l32-32a8 8 0 0 0-11.32-11.32m0 64L56 132.69l-10.34-10.35a8 8 0 0 0-11.32 11.32l16 16a8 8 0 0 0 11.32 0l32-32a8 8 0 0 0-11.32-11.32m0 64L56 196.69l-10.34-10.35a8 8 0 0 0-11.32 11.32l16 16a8 8 0 0 0 11.32 0l32-32a8 8 0 0 0-11.32-11.32" />
                            </svg>
                            Onboarding
                        </a>
                    </li>
                    <li>
                        <a title="Sponsor us" class="menu-item" href="https://coolify.io/sponsorships"
                            target="_blank">
                            <svg class="text-pink-500 icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <g fill="none" stroke="currentColor" stroke-linecap="round"
                                    stroke-linejoin="round" stroke-width="2">
                                    <path d="M19.5 12.572L12 20l-7.5-7.428A5 5 0 1 1 12 6.006a5 5 0 1 1 7.5 6.572" />
                                    <path
                                        d="M12 6L8.707 9.293a1 1 0 0 0 0 1.414l.543.543c.69.69 1.81.69 2.5 0l1-1a3.182 3.182 0 0 1 4.5 0l2.25 2.25m-7 3l2 2M15 13l2 2" />
                                </g>
                            </svg>
                            Sponsor us
                        </a>
                    </li>
                @endif
                <li>
                    <x-modal-input title="How can we help?">
                        <x-slot:content>
                            <div title="Send us feedback or get help!" class="cursor-pointer menu-item"
                                wire:click="help">
                                <svg class="icon" viewBox="0 0 256 256" xmlns="http://www.w3.org/2000/svg">
                                    <path fill="currentColor"
                                        d="M140 180a12 12 0 1 1-12-12a12 12 0 0 1 12 12M128 72c-22.06 0-40 16.15-40 36v4a8 8 0 0 0 16 0v-4c0-11 10.77-20 24-20s24 9 24 20s-10.77 20-24 20a8 8 0 0 0-8 8v8a8 8 0 0 0 16 0v-.72c18.24-3.35 32-17.9 32-35.28c0-19.85-17.94-36-40-36m104 56A104 104 0 1 1 128 24a104.11 104.11 0 0 1 104 104m-16 0a88 88 0 1 0-88 88a88.1 88.1 0 0 0 88-88" />
                                </svg>
                                Feedback
                            </div>
                        </x-slot:content>
                        <livewire:help />
                    </x-modal-input>
                </li>
                <li>
                    <form action="/logout" method="POST">
                        @csrf
                        <button title="Logout" type="submit" class="gap-2 mb-6 menu-item">
                            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path fill="currentColor"
                                    d="M12 22C6.477 22 2 17.523 2 12S6.477 2 12 2a9.985 9.985 0 0 1 8 4h-2.71a8 8 0 1 0 .001 12h2.71A9.985 9.985 0 0 1 12 22m7-6v-3h-8v-2h8V8l5 4z" />
                            </svg>
                            Logout
                        </button>
                    </form>
                </li>
            </ul>
        </li>
        {{-- <li>
            <div class="text-xs font-semibold leading-6 text-gray-400">Your teams</div>
            <ul role="list" class="mt-2 -mx-2 space-y-1">
                <li>
                    <a href="#"
                        class="flex p-2 text-sm font-semibold leading-6 text-gray-700 rounded-md hover:text-indigo-600 hover:bg-gray-50 group gap-x-3">
                        <span
                            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg border text-[0.625rem] font-medium bg-white text-gray-400 border-gray-200 group-hover:border-indigo-600 group-hover:text-indigo-600">H</span>
                        <span class="truncate">Heroicons</span>
                    </a>
                </li>
                <li>
                    <a href="#"
                        class="flex p-2 text-sm font-semibold leading-6 text-gray-700 rounded-md hover:text-indigo-600 hover:bg-gray-50 group gap-x-3">
                        <span
                            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg border text-[0.625rem] font-medium bg-white text-gray-400 border-gray-200 group-hover:border-indigo-600 group-hover:text-indigo-600">T</span>
                        <span class="truncate">Tailwind Labs</span>
                    </a>
                </li>
                <li>
                    <a href="#"
                        class="flex p-2 text-sm font-semibold leading-6 text-gray-700 rounded-md hover:text-indigo-600 hover:bg-gray-50 group gap-x-3">
                        <span
                            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg border text-[0.625rem] font-medium bg-white text-gray-400 border-gray-200 group-hover:border-indigo-600 group-hover:text-indigo-600">W</span>
                        <span class="truncate">Workcation</span>
                    </a>
                </li>
            </ul>
        </li>
        <li class="mt-auto -mx-6">
            <a href="#"
                class="flex items-center px-6 py-3 text-sm font-semibold leading-6 text-gray-900 gap-x-4 hover:bg-gray-50">
                <img class="w-8 h-8 rounded-full bg-gray-50"
                    src="https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80"
                    alt="">
                <span class="sr-only">Your profile</span>
                <span aria-hidden="true">Tom Cook</span>
            </a>
        </li> --}}
    </ul>
</nav>
