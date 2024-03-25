<div x-data x-init="$wire.loadServers">
    <div class="flex flex-col gap-4 lg:flex-row ">
        <h1>New Resource</h1>
        <div class="w-full pb-4 lg:w-96 lg:pb-0">
            <x-forms.select wire:model="selectedEnvironment">
                @foreach ($environments as $environment)
                    <option value="{{ $environment->name }}">Environment: {{ $environment->name }}</option>
                @endforeach
            </x-forms.select>
        </div>
    </div>
    <div class="pb-4 ">Deploy resources, like Applications, Databases, Services...</div>
    <div class="flex flex-col gap-4 pt-4">
        @if ($current_step === 'type')
            <h2>Applications</h2>
            <h4>Git Based</h4>
            <div class="grid justify-start grid-cols-1 gap-4 text-left xl:grid-cols-1">
                <x-resource-view wire="setType('public')">
                    <x-slot:title>Public Repository</x-slot>
                    <x-slot:description>
                        You can deploy any kind of public repositories from the supported git providers.
                    </x-slot>
                    <x-slot:logo>
                        <img class="w-[4.5rem]
                            aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100 "
                            src="{{ asset('svgs/git.svg') }}">
                    </x-slot:logo>
                </x-resource-view>
                <x-resource-view wire="setType('private-gh-app')">
                    <x-slot:title>Private Repository (with GitHub App)</x-slot>
                    <x-slot:description>
                        You can deploy public & private repositories through your GitHub Apps.
                    </x-slot>
                    <x-slot:logo>
                        <svg class="w-[4.5rem]
                        aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100 dark:fill-black"
                            xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128">
                            <g fill="currentColor">
                                <path fill-rule="evenodd" clip-rule="evenodd"
                                    d="M64 1.512c-23.493 0-42.545 19.047-42.545 42.545 0 18.797 12.19 34.745 29.095 40.37 2.126.394 2.907-.923 2.907-2.047 0-1.014-.04-4.366-.058-7.92-11.837 2.573-14.334-5.02-14.334-5.02-1.935-4.918-4.724-6.226-4.724-6.226-3.86-2.64.29-2.586.29-2.586 4.273.3 6.523 4.385 6.523 4.385 3.794 6.504 9.953 4.623 12.38 3.536.383-2.75 1.485-4.628 2.702-5.69-9.45-1.075-19.384-4.724-19.384-21.026 0-4.645 1.662-8.44 4.384-11.42-.442-1.072-1.898-5.4.412-11.26 0 0 3.572-1.142 11.7 4.363 3.395-.943 7.035-1.416 10.65-1.432 3.616.017 7.258.49 10.658 1.432 8.12-5.504 11.688-4.362 11.688-4.362 2.316 5.86.86 10.187.418 11.26 2.728 2.978 4.378 6.774 4.378 11.42 0 16.34-9.953 19.938-19.427 20.99 1.526 1.32 2.886 3.91 2.886 7.88 0 5.692-.048 10.273-.048 11.674 0 1.13.766 2.458 2.922 2.04 16.896-5.632 29.07-21.574 29.07-40.365C106.545 20.56 87.497 1.512 64 1.512z" />
                                <path
                                    d="M37.57 62.596c-.095.212-.428.275-.73.13-.31-.14-.482-.427-.382-.64.09-.216.424-.277.733-.132.31.14.486.43.38.642zM39.293 64.52c-.203.187-.6.1-.87-.198-.278-.297-.33-.694-.124-.884.208-.188.593-.1.87.197.28.3.335.693.123.884zm1.677 2.448c-.26.182-.687.012-.95-.367-.262-.377-.262-.83.005-1.013.264-.182.684-.018.95.357.262.385.262.84-.005 1.024zm2.298 2.368c-.233.257-.73.188-1.093-.163-.372-.343-.475-.83-.242-1.087.237-.257.736-.185 1.102.163.37.342.482.83.233 1.086zm3.172 1.374c-.104.334-.582.485-1.064.344-.482-.146-.796-.536-.7-.872.1-.336.582-.493 1.067-.342.48.144.795.53.696.87zm3.48.255c.013.35-.396.642-.902.648-.508.012-.92-.272-.926-.618 0-.354.4-.642.908-.65.506-.01.92.272.92.62zm3.24-.551c.06.342-.29.694-.793.787-.494.092-.95-.12-1.014-.46-.06-.35.297-.7.79-.792.503-.088.953.118 1.017.466zm0 0" />
                            </g>
                            <path
                                d="M24.855 108.302h-10.7a.5.5 0 00-.5.5v5.232a.5.5 0 00.5.5h4.173v6.5s-.937.32-3.53.32c-3.056 0-7.327-1.116-7.327-10.508 0-9.393 4.448-10.63 8.624-10.63 3.614 0 5.17.636 6.162.943.31.094.6-.216.6-.492l1.193-5.055a.468.468 0 00-.192-.39c-.403-.288-2.857-1.66-9.058-1.66-7.144 0-14.472 3.038-14.472 17.65 0 14.61 8.39 16.787 15.46 16.787 5.854 0 9.405-2.502 9.405-2.502.146-.08.162-.285.162-.38v-16.316a.5.5 0 00-.5-.5zM79.506 94.81H73.48a.5.5 0 00-.498.503l.002 11.644h-9.392V95.313a.5.5 0 00-.497-.503H57.07a.5.5 0 00-.498.503v31.53c0 .277.224.503.498.503h6.025a.5.5 0 00.497-.504v-13.486h9.392l-.016 13.486c0 .278.224.504.5.504h6.038a.5.5 0 00.497-.504v-31.53a.497.497 0 00-.497-.502zm-47.166.717c-2.144 0-3.884 1.753-3.884 3.923 0 2.167 1.74 3.925 3.884 3.925 2.146 0 3.885-1.758 3.885-3.925 0-2.17-1.74-3.923-3.885-3.923zm2.956 9.608H29.29c-.276 0-.522.284-.522.56v20.852c0 .613.382.795.876.795h5.41c.595 0 .74-.292.74-.805v-20.899a.5.5 0 00-.498-.502zm67.606.047h-5.98a.5.5 0 00-.496.504v15.46s-1.52 1.11-3.675 1.11-2.727-.977-2.727-3.088v-13.482a.5.5 0 00-.497-.504h-6.068a.502.502 0 00-.498.504v14.502c0 6.27 3.495 7.804 8.302 7.804 3.944 0 7.124-2.18 7.124-2.18s.15 1.15.22 1.285c.07.136.247.273.44.273l3.86-.017a.502.502 0 00.5-.504l-.003-21.166a.504.504 0 00-.5-.502zm16.342-.708c-3.396 0-5.706 1.515-5.706 1.515V95.312a.5.5 0 00-.497-.503H107a.5.5 0 00-.5.503v31.53a.5.5 0 00.5.503h4.192c.19 0 .332-.097.437-.268.103-.17.254-1.454.254-1.454s2.47 2.34 7.148 2.34c5.49 0 8.64-2.784 8.64-12.502s-5.03-10.988-8.428-10.988zm-2.36 17.764c-2.073-.063-3.48-1.004-3.48-1.004v-9.985s1.388-.85 3.09-1.004c2.153-.193 4.228.458 4.228 5.594 0 5.417-.935 6.486-3.837 6.398zm-63.689-.118c-.263 0-.937.107-1.63.107-2.22 0-2.973-1.032-2.973-2.368v-8.866h4.52a.5.5 0 00.5-.504v-4.856a.5.5 0 00-.5-.502h-4.52l-.007-5.97c0-.227-.116-.34-.378-.34h-6.16c-.238 0-.367.106-.367.335v6.17s-3.087.745-3.295.805a.5.5 0 00-.36.48v3.877a.5.5 0 00.497.503h3.158v9.328c0 6.93 4.86 7.61 8.14 7.61 1.497 0 3.29-.48 3.586-.59.18-.067.283-.252.283-.453l.004-4.265a.51.51 0 00-.5-.502z"
                                fill="currentColor" />
                        </svg>
                    </x-slot:logo>
                </x-resource-view>
                <x-resource-view wire="setType('private-deploy-key')">
                    <x-slot:title> Private Repository (with deploy key)</x-slot>
                    <x-slot:description>
                        You can deploy public & private repositories with a simple deploy key (SSH key).
                    </x-slot>
                    <x-slot:logo>
                        <img class="w-[4.5rem]
                            aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100"
                            src="{{ asset('svgs/git.svg') }}">
                    </x-slot:logo>
                </x-resource-view>
            </div>
            <h4>Docker Based</h4>
            <div class="grid justify-start grid-cols-1 gap-4 text-left xl:grid-cols-3">
                <x-resource-view wire="setType('dockerfile')">
                    <x-slot:title>Dockerfile</x-slot>
                    <x-slot:description>
                        You can deploy a simple Dockerfile, without Git.
                    </x-slot>
                    <x-slot:logo>
                        <svg class="w-[4.5rem]
                        aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100 dark:fill-black"
                            xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128">
                            <path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor"
                                d="M20 96.9v-8.1c0-1.1.7-1.9 1.8-1.9h.3c1.1 0 1.8.9 1.8 1.9v17c0 4.1-2 7.4-5.6 9.5-1.7 1-3.5 1.5-5.4 1.5h-.8c-4.1 0-7.4-2-9.5-5.6-1-1.7-1.5-3.5-1.5-5.4v-.8c0-4.1 2-7.4 5.6-9.5 1.7-1 3.5-1.5 5.4-1.5h.8c2.7.1 5.1 1.1 7.1 2.9zm-15.1 8.5c0 3 1.5 5.2 4.1 6.7 1.1.6 2.2.9 3.4.9 2.9 0 5.1-1.4 6.6-3.9.7-1.2 1-2.4 1-3.8 0-2.6-1.2-4.6-3.3-6.1-1.3-.9-2.7-1.4-4.2-1.4-3.2 0-5.5 1.6-6.9 4.5-.5 1-.7 2.1-.7 3.1zm32.2-11.3h.5c4.4 0 7.8 2.1 9.9 6 .9 1.5 1.3 3.2 1.3 5v.8c0 4.1-2 7.4-5.6 9.5-1.7 1-3.5 1.5-5.4 1.5H37c-4.1 0-7.4-2-9.5-5.6-1-1.7-1.5-3.5-1.5-5.4v-.8c0-4.1 2.1-7.4 5.6-9.5 1.7-1.1 3.6-1.5 5.5-1.5zm-7.2 11.3c0 2.9 1.4 5 3.9 6.5 1.2.7 2.4 1 3.8 1 2.9 0 5-1.5 6.5-3.9.7-1.2 1-2.4 1-3.8 0-2.7-1.3-4.8-3.5-6.3-1.2-.8-2.6-1.2-4-1.2-3.2 0-5.5 1.6-6.9 4.5-.6 1.1-.8 2.2-.8 3.2zm34.8-7.2c-.6-.3-1.7-.4-2.3-.4-3.2-.1-5.5 1.7-6.9 4.5-.5 1-.7 2-.7 3.1 0 3.3 1.7 5.6 4.6 7 1.1.5 2.4.6 3.6.6 1 0 2.5-.6 3.4-1.1l.2-.1h.8c.9.2 1.5.7 1.5 1.7v.4c0 2.3-4.3 2.9-5.9 3-5.7.4-10-2.7-11.6-8.2-.3-.9-.4-1.9-.4-2.9v-.8c0-4.1 2.1-7.4 5.6-9.5 1.7-1 3.5-1.5 5.4-1.5h.8c2 0 3.9.6 5.6 1.7l.1.1.1.1c.2.3.3.6.3 1v.4c0 1-.7 1.5-1.6 1.7H67c-.5 0-1.8-.6-2.3-.8zm12.4 2.6c1.5-1.5 3-3 4.5-4.4.4-.4 2-2.1 2.6-2.1h.8c.9.2 1.5.7 1.5 1.7v.4c0 .6-.7 1.4-1.2 1.8l-2.7 2.7-4.6 4.7c2 2 4 4 5.9 6l1.6 1.7c.2.2.5.4.6.7.2.3.3.6.3.9v.5c-.2.9-.8 1.6-1.7 1.6h-.3c-.6 0-1.3-.7-1.8-1.1-.9-.8-1.8-1.7-2.6-2.6l-2.9-2.9v4.6c0 1.1-.7 1.9-1.8 1.9H75c-1.1 0-1.8-.9-1.8-1.9V88.9c0-1.1.7-1.9 1.8-1.9h.3c1.1 0 1.8.8 1.8 1.9v11.9zm47.6-6.6h.4c1.1 0 1.9.8 1.9 1.9 0 1.6-1.5 2-2.8 2-1.7 0-3.4 1-4.5 2.2-1.5 1.5-2.1 3.3-2.1 5.4v9.2c0 1.1-.7 1.9-1.8 1.9h-.3c-1.1 0-1.8-.9-1.8-1.9v-9.8c0-3.8 1.8-6.8 4.9-9 1.8-1.2 3.9-1.9 6.1-1.9zm-27.1 18.3c1.4.5 3 .4 4.4.2.7-.3 2.6-1.1 3.3-1h.2c.4.2.8.5 1 .9.5 1 .3 2-.7 2.6l-.3.2c-3.6 2.1-7.5 1.8-11.1-.2-1.7-.9-3-2.3-4-4l-.2-.4c-2.3-4-2-8.3.6-12.1.9-1.3 2.1-2.3 3.5-3.1l.5-.3c3.4-2 7.1-1.8 10.6-.1 1.9.9 3.4 2.3 4.5 4.1l.2.3c.8 1.3-.2 2.5-1.2 3.3-1.2.9-2.4 2-3.5 3-2.7 2.2-5.3 4.4-7.8 6.6zm-3.3-2.3l8.5-7.3c1-.8 2-1.7 3-2.6-.8-1-2.1-1.7-3.1-2.1-2.2-.8-4.4-.6-6.4.6-2.6 1.5-3.8 4-3.7 7 0 1.2.4 2.3 1 3.4.2.4.4.7.7 1M73.7 33.7H85v11.5h5.7c2.6 0 5.3-.5 7.8-1.3 1.2-.4 2.6-1 3.8-1.7-1.6-2.1-2.4-4.7-2.6-7.3-.3-3.5.4-8.1 2.8-10.8l1.2-1.4 1.4 1.1c3.6 2.9 6.5 6.8 7.1 11.4 4.3-1.3 9.3-1 13.1 1.2l1.5.9-.8 1.6c-3.2 6.2-9.9 8.2-16.4 7.8-9.8 24.3-31 35.8-56.8 35.8-13.3 0-25.5-5-32.5-16.8l-.1-.2-1-2.1c-2.4-5.2-3.1-10.9-2.6-16.6l.2-1.7h9.6V33.7h11.3V22.4h22.5V11.1h13.5v22.6z" />
                            <path fill="#00AADA"
                                d="M110.2 37.9c.8-5.9-3.6-10.5-6.4-12.7-3.1 3.6-3.6 13.2 1.3 17.2-2.8 2.4-8.5 4.7-14.5 4.7H18.4c-.6 6.2.5 11.9 3 16.8l.8 1.5c.5.9 1.1 1.7 1.7 2.6 3 .2 5.7.3 8.2.2 4.9-.1 8.9-.7 12-1.7.5-.2.9.1 1.1.5.2.5-.1.9-.5 1.1-.4.1-.8.3-1.3.4-2.4.7-5 1.1-8.3 1.3h-.6c-1.3.1-2.7.1-4.2.1-1.6 0-3.1 0-4.9-.1 6 6.8 15.4 10.8 27.2 10.8 25 0 46.2-11.1 55.5-35.9 6.7.7 13.1-1 16-6.7-4.5-2.6-10.5-1.8-13.9-.1z" />
                            <path fill="#28B8EB"
                                d="M110.2 37.9c.8-5.9-3.6-10.5-6.4-12.7-3.1 3.6-3.6 13.2 1.3 17.2-2.8 2.4-8.5 4.7-14.5 4.7h-68c-.3 9.5 3.2 16.7 9.5 21 4.9-.1 8.9-.7 12-1.7.5-.2.9.1 1.1.5.2.5-.1.9-.5 1.1-.4.1-.8.3-1.3.4-2.4.7-5.2 1.2-8.5 1.4l-.1-.1c8.5 4.4 20.8 4.3 35-1.1 15.8-6.1 30.6-17.7 40.9-30.9-.2.1-.3.2-.5.2z" />
                            <path fill="#028BB8"
                                d="M18.5 54.6c.4 3.3 1.4 6.4 2.9 9.3l.8 1.5c.5.9 1.1 1.7 1.7 2.6 3 .2 5.7.3 8.2.2 4.9-.1 8.9-.7 12-1.7.5-.2.9.1 1.1.5.2.5-.1.9-.5 1.1-.4.1-.8.3-1.3.4-2.4.7-5.2 1.2-8.5 1.4h-.4c-1.3.1-2.7.1-4.1.1-1.6 0-3.2 0-4.9-.1 6 6.8 15.5 10.8 27.3 10.8 21.4 0 40-8.1 50.8-26H18.5v-.1z" />
                            <path fill="#019BC6"
                                d="M23.3 54.6c1.3 5.8 4.3 10.4 8.8 13.5 4.9-.1 8.9-.7 12-1.7.5-.2.9.1 1.1.5.2.5-.1.9-.5 1.1-.4.1-.8.3-1.3.4-2.4.7-5.2 1.2-8.6 1.4 8.5 4.4 20.8 4.3 34.9-1.1 8.5-3.3 16.8-8.2 24.2-14.1H23.3z" />
                            <path fill-rule="evenodd" clip-rule="evenodd" fill="#00ACD3"
                                d="M28.2 35.5H38v9.8h-9.8v-9.8zm.8.9h.8v8.1H29v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1H32v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm3.1-12.1h9.8V34h-9.8v-9.7zm.8.8h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1z" />
                            <path fill-rule="evenodd" clip-rule="evenodd" fill="#23C2EE"
                                d="M39.5 35.5h9.8v9.8h-9.8v-9.8zm.8.9h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1z" />
                            <path fill-rule="evenodd" clip-rule="evenodd" fill="#00ACD3"
                                d="M50.8 35.5h9.8v9.8h-9.8v-9.8zm.8.9h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1H53v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1H56v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1z" />
                            <path fill-rule="evenodd" clip-rule="evenodd" fill="#23C2EE"
                                d="M50.8 24.3h9.8V34h-9.8v-9.7zm.8.8h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1H53v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1H56v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zM62 35.5h9.8v9.8H62v-9.8zm.9.9h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1z" />
                            <path fill-rule="evenodd" clip-rule="evenodd" fill="#00ACD3"
                                d="M62 24.3h9.8V34H62v-9.7zm.9.8h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1z" />
                            <path fill-rule="evenodd" clip-rule="evenodd" fill="#23C2EE"
                                d="M62 13h9.8v9.8H62V13zm.9.8h.8V22h-.8v-8.2zm1.4 0h.8V22h-.8v-8.2zm1.5 0h.8V22h-.8v-8.2zm1.5 0h.8V22h-.8v-8.2zm1.4 0h.8V22h-.8v-8.2zm1.5 0h.8V22h-.8v-8.2z" />
                            <path fill-rule="evenodd" clip-rule="evenodd" fill="#00ACD3"
                                d="M73.3 35.5h9.8v9.8h-9.8v-9.8zm.8.9h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1H80v-8.1zm1.5 0h.8v8.1h-.8v-8.1z" />
                            <path fill-rule="evenodd" clip-rule="evenodd" fill="#D4EEF1"
                                d="M48.6 61.2c1.5 0 2.7 1.2 2.7 2.7 0 1.5-1.2 2.7-2.7 2.7-1.5 0-2.7-1.2-2.7-2.7.1-1.5 1.3-2.7 2.7-2.7" />
                            <path fill-rule="evenodd" clip-rule="evenodd" fill="#3A4D54"
                                d="M48.6 61.9c.2 0 .5 0 .7.1-.2.1-.4.4-.4.7 0 .4.4.8.8.8.3 0 .6-.2.7-.4.1.2.1.5.1.7 0 1.1-.9 1.9-1.9 1.9-1.1 0-1.9-.9-1.9-1.9 0-1 .9-1.9 1.9-1.9M1 55.6h125.3c-2.7-.7-8.6-1.6-7.7-5.2-5 5.7-16.9 4-20 1.2-3.4 4.9-23 3-24.3-.8-4.2 5-17.3 5-21.5 0-1.4 3.8-21 5.7-24.3.8-3 2.8-15 4.5-20-1.2 1.1 3.5-4.8 4.5-7.5 5.2" />
                            <path fill="#BFDBE0"
                                d="M55.8 80.6c-6.7-3.2-10.3-7.5-12.4-12.2-2.5.7-5.5 1.2-8.9 1.4-1.3.1-2.7.1-4.1.1-1.7 0-3.4 0-5.2-.1 6.1 6.1 13.7 10.8 27.6 10.9 1-.1 2-.1 3-.1z" />
                            <path fill="#D4EEF1"
                                d="M45.9 72.7c-.9-1.3-1.8-2.8-2.5-4.3-2.5.7-5.5 1.2-8.9 1.4 2.4 1.3 5.8 2.5 11.4 2.9z" />
                        </svg>
                    </x-slot:logo>
                </x-resource-view>
                <x-resource-view wire="setType('docker-compose-empty')">
                    <x-slot:title>Docker Compose</x-slot>
                    <x-slot:description>
                        You can deploy complex application easily with Docker Compose, without Git.
                    </x-slot>
                    <x-slot:logo>
                        <div
                            class="w-[4.5rem]
                        aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100 dark:fill-black">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128">
                                <path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor"
                                    d="M20 96.9v-8.1c0-1.1.7-1.9 1.8-1.9h.3c1.1 0 1.8.9 1.8 1.9v17c0 4.1-2 7.4-5.6 9.5-1.7 1-3.5 1.5-5.4 1.5h-.8c-4.1 0-7.4-2-9.5-5.6-1-1.7-1.5-3.5-1.5-5.4v-.8c0-4.1 2-7.4 5.6-9.5 1.7-1 3.5-1.5 5.4-1.5h.8c2.7.1 5.1 1.1 7.1 2.9zm-15.1 8.5c0 3 1.5 5.2 4.1 6.7 1.1.6 2.2.9 3.4.9 2.9 0 5.1-1.4 6.6-3.9.7-1.2 1-2.4 1-3.8 0-2.6-1.2-4.6-3.3-6.1-1.3-.9-2.7-1.4-4.2-1.4-3.2 0-5.5 1.6-6.9 4.5-.5 1-.7 2.1-.7 3.1zm32.2-11.3h.5c4.4 0 7.8 2.1 9.9 6 .9 1.5 1.3 3.2 1.3 5v.8c0 4.1-2 7.4-5.6 9.5-1.7 1-3.5 1.5-5.4 1.5H37c-4.1 0-7.4-2-9.5-5.6-1-1.7-1.5-3.5-1.5-5.4v-.8c0-4.1 2.1-7.4 5.6-9.5 1.7-1.1 3.6-1.5 5.5-1.5zm-7.2 11.3c0 2.9 1.4 5 3.9 6.5 1.2.7 2.4 1 3.8 1 2.9 0 5-1.5 6.5-3.9.7-1.2 1-2.4 1-3.8 0-2.7-1.3-4.8-3.5-6.3-1.2-.8-2.6-1.2-4-1.2-3.2 0-5.5 1.6-6.9 4.5-.6 1.1-.8 2.2-.8 3.2zm34.8-7.2c-.6-.3-1.7-.4-2.3-.4-3.2-.1-5.5 1.7-6.9 4.5-.5 1-.7 2-.7 3.1 0 3.3 1.7 5.6 4.6 7 1.1.5 2.4.6 3.6.6 1 0 2.5-.6 3.4-1.1l.2-.1h.8c.9.2 1.5.7 1.5 1.7v.4c0 2.3-4.3 2.9-5.9 3-5.7.4-10-2.7-11.6-8.2-.3-.9-.4-1.9-.4-2.9v-.8c0-4.1 2.1-7.4 5.6-9.5 1.7-1 3.5-1.5 5.4-1.5h.8c2 0 3.9.6 5.6 1.7l.1.1.1.1c.2.3.3.6.3 1v.4c0 1-.7 1.5-1.6 1.7H67c-.5 0-1.8-.6-2.3-.8zm12.4 2.6c1.5-1.5 3-3 4.5-4.4.4-.4 2-2.1 2.6-2.1h.8c.9.2 1.5.7 1.5 1.7v.4c0 .6-.7 1.4-1.2 1.8l-2.7 2.7-4.6 4.7c2 2 4 4 5.9 6l1.6 1.7c.2.2.5.4.6.7.2.3.3.6.3.9v.5c-.2.9-.8 1.6-1.7 1.6h-.3c-.6 0-1.3-.7-1.8-1.1-.9-.8-1.8-1.7-2.6-2.6l-2.9-2.9v4.6c0 1.1-.7 1.9-1.8 1.9H75c-1.1 0-1.8-.9-1.8-1.9V88.9c0-1.1.7-1.9 1.8-1.9h.3c1.1 0 1.8.8 1.8 1.9v11.9zm47.6-6.6h.4c1.1 0 1.9.8 1.9 1.9 0 1.6-1.5 2-2.8 2-1.7 0-3.4 1-4.5 2.2-1.5 1.5-2.1 3.3-2.1 5.4v9.2c0 1.1-.7 1.9-1.8 1.9h-.3c-1.1 0-1.8-.9-1.8-1.9v-9.8c0-3.8 1.8-6.8 4.9-9 1.8-1.2 3.9-1.9 6.1-1.9zm-27.1 18.3c1.4.5 3 .4 4.4.2.7-.3 2.6-1.1 3.3-1h.2c.4.2.8.5 1 .9.5 1 .3 2-.7 2.6l-.3.2c-3.6 2.1-7.5 1.8-11.1-.2-1.7-.9-3-2.3-4-4l-.2-.4c-2.3-4-2-8.3.6-12.1.9-1.3 2.1-2.3 3.5-3.1l.5-.3c3.4-2 7.1-1.8 10.6-.1 1.9.9 3.4 2.3 4.5 4.1l.2.3c.8 1.3-.2 2.5-1.2 3.3-1.2.9-2.4 2-3.5 3-2.7 2.2-5.3 4.4-7.8 6.6zm-3.3-2.3l8.5-7.3c1-.8 2-1.7 3-2.6-.8-1-2.1-1.7-3.1-2.1-2.2-.8-4.4-.6-6.4.6-2.6 1.5-3.8 4-3.7 7 0 1.2.4 2.3 1 3.4.2.4.4.7.7 1M73.7 33.7H85v11.5h5.7c2.6 0 5.3-.5 7.8-1.3 1.2-.4 2.6-1 3.8-1.7-1.6-2.1-2.4-4.7-2.6-7.3-.3-3.5.4-8.1 2.8-10.8l1.2-1.4 1.4 1.1c3.6 2.9 6.5 6.8 7.1 11.4 4.3-1.3 9.3-1 13.1 1.2l1.5.9-.8 1.6c-3.2 6.2-9.9 8.2-16.4 7.8-9.8 24.3-31 35.8-56.8 35.8-13.3 0-25.5-5-32.5-16.8l-.1-.2-1-2.1c-2.4-5.2-3.1-10.9-2.6-16.6l.2-1.7h9.6V33.7h11.3V22.4h22.5V11.1h13.5v22.6z" />
                                <path fill="#00AADA"
                                    d="M110.2 37.9c.8-5.9-3.6-10.5-6.4-12.7-3.1 3.6-3.6 13.2 1.3 17.2-2.8 2.4-8.5 4.7-14.5 4.7H18.4c-.6 6.2.5 11.9 3 16.8l.8 1.5c.5.9 1.1 1.7 1.7 2.6 3 .2 5.7.3 8.2.2 4.9-.1 8.9-.7 12-1.7.5-.2.9.1 1.1.5.2.5-.1.9-.5 1.1-.4.1-.8.3-1.3.4-2.4.7-5 1.1-8.3 1.3h-.6c-1.3.1-2.7.1-4.2.1-1.6 0-3.1 0-4.9-.1 6 6.8 15.4 10.8 27.2 10.8 25 0 46.2-11.1 55.5-35.9 6.7.7 13.1-1 16-6.7-4.5-2.6-10.5-1.8-13.9-.1z" />
                                <path fill="#28B8EB"
                                    d="M110.2 37.9c.8-5.9-3.6-10.5-6.4-12.7-3.1 3.6-3.6 13.2 1.3 17.2-2.8 2.4-8.5 4.7-14.5 4.7h-68c-.3 9.5 3.2 16.7 9.5 21 4.9-.1 8.9-.7 12-1.7.5-.2.9.1 1.1.5.2.5-.1.9-.5 1.1-.4.1-.8.3-1.3.4-2.4.7-5.2 1.2-8.5 1.4l-.1-.1c8.5 4.4 20.8 4.3 35-1.1 15.8-6.1 30.6-17.7 40.9-30.9-.2.1-.3.2-.5.2z" />
                                <path fill="#028BB8"
                                    d="M18.5 54.6c.4 3.3 1.4 6.4 2.9 9.3l.8 1.5c.5.9 1.1 1.7 1.7 2.6 3 .2 5.7.3 8.2.2 4.9-.1 8.9-.7 12-1.7.5-.2.9.1 1.1.5.2.5-.1.9-.5 1.1-.4.1-.8.3-1.3.4-2.4.7-5.2 1.2-8.5 1.4h-.4c-1.3.1-2.7.1-4.1.1-1.6 0-3.2 0-4.9-.1 6 6.8 15.5 10.8 27.3 10.8 21.4 0 40-8.1 50.8-26H18.5v-.1z" />
                                <path fill="#019BC6"
                                    d="M23.3 54.6c1.3 5.8 4.3 10.4 8.8 13.5 4.9-.1 8.9-.7 12-1.7.5-.2.9.1 1.1.5.2.5-.1.9-.5 1.1-.4.1-.8.3-1.3.4-2.4.7-5.2 1.2-8.6 1.4 8.5 4.4 20.8 4.3 34.9-1.1 8.5-3.3 16.8-8.2 24.2-14.1H23.3z" />
                                <path fill-rule="evenodd" clip-rule="evenodd" fill="#00ACD3"
                                    d="M28.2 35.5H38v9.8h-9.8v-9.8zm.8.9h.8v8.1H29v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1H32v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm3.1-12.1h9.8V34h-9.8v-9.7zm.8.8h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1z" />
                                <path fill-rule="evenodd" clip-rule="evenodd" fill="#23C2EE"
                                    d="M39.5 35.5h9.8v9.8h-9.8v-9.8zm.8.9h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1z" />
                                <path fill-rule="evenodd" clip-rule="evenodd" fill="#00ACD3"
                                    d="M50.8 35.5h9.8v9.8h-9.8v-9.8zm.8.9h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1H53v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1H56v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1z" />
                                <path fill-rule="evenodd" clip-rule="evenodd" fill="#23C2EE"
                                    d="M50.8 24.3h9.8V34h-9.8v-9.7zm.8.8h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1H53v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1H56v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zM62 35.5h9.8v9.8H62v-9.8zm.9.9h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1z" />
                                <path fill-rule="evenodd" clip-rule="evenodd" fill="#00ACD3"
                                    d="M62 24.3h9.8V34H62v-9.7zm.9.8h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1z" />
                                <path fill-rule="evenodd" clip-rule="evenodd" fill="#23C2EE"
                                    d="M62 13h9.8v9.8H62V13zm.9.8h.8V22h-.8v-8.2zm1.4 0h.8V22h-.8v-8.2zm1.5 0h.8V22h-.8v-8.2zm1.5 0h.8V22h-.8v-8.2zm1.4 0h.8V22h-.8v-8.2zm1.5 0h.8V22h-.8v-8.2z" />
                                <path fill-rule="evenodd" clip-rule="evenodd" fill="#00ACD3"
                                    d="M73.3 35.5h9.8v9.8h-9.8v-9.8zm.8.9h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1H80v-8.1zm1.5 0h.8v8.1h-.8v-8.1z" />
                                <path fill-rule="evenodd" clip-rule="evenodd" fill="#D4EEF1"
                                    d="M48.6 61.2c1.5 0 2.7 1.2 2.7 2.7 0 1.5-1.2 2.7-2.7 2.7-1.5 0-2.7-1.2-2.7-2.7.1-1.5 1.3-2.7 2.7-2.7" />
                                <path fill-rule="evenodd" clip-rule="evenodd" fill="#3A4D54"
                                    d="M48.6 61.9c.2 0 .5 0 .7.1-.2.1-.4.4-.4.7 0 .4.4.8.8.8.3 0 .6-.2.7-.4.1.2.1.5.1.7 0 1.1-.9 1.9-1.9 1.9-1.1 0-1.9-.9-1.9-1.9 0-1 .9-1.9 1.9-1.9M1 55.6h125.3c-2.7-.7-8.6-1.6-7.7-5.2-5 5.7-16.9 4-20 1.2-3.4 4.9-23 3-24.3-.8-4.2 5-17.3 5-21.5 0-1.4 3.8-21 5.7-24.3.8-3 2.8-15 4.5-20-1.2 1.1 3.5-4.8 4.5-7.5 5.2" />
                                <path fill="#BFDBE0"
                                    d="M55.8 80.6c-6.7-3.2-10.3-7.5-12.4-12.2-2.5.7-5.5 1.2-8.9 1.4-1.3.1-2.7.1-4.1.1-1.7 0-3.4 0-5.2-.1 6.1 6.1 13.7 10.8 27.6 10.9 1-.1 2-.1 3-.1z" />
                                <path fill="#D4EEF1"
                                    d="M45.9 72.7c-.9-1.3-1.8-2.8-2.5-4.3-2.5.7-5.5 1.2-8.9 1.4 2.4 1.3 5.8 2.5 11.4 2.9z" />
                            </svg>
                        </div>
                    </x-slot:logo>
                </x-resource-view>
                <x-resource-view wire="setType('docker-image')">
                    <x-slot:title>Existing Docker Image</x-slot>
                    <x-slot:description>
                        You can deploy an existing Docker Image from any Registry, without Git.
                    </x-slot>
                    <x-slot:logo>
                        <div
                            class="w-[4.5rem]
                        aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100 dark:fill-black">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128">
                                <path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor"
                                    d="M20 96.9v-8.1c0-1.1.7-1.9 1.8-1.9h.3c1.1 0 1.8.9 1.8 1.9v17c0 4.1-2 7.4-5.6 9.5-1.7 1-3.5 1.5-5.4 1.5h-.8c-4.1 0-7.4-2-9.5-5.6-1-1.7-1.5-3.5-1.5-5.4v-.8c0-4.1 2-7.4 5.6-9.5 1.7-1 3.5-1.5 5.4-1.5h.8c2.7.1 5.1 1.1 7.1 2.9zm-15.1 8.5c0 3 1.5 5.2 4.1 6.7 1.1.6 2.2.9 3.4.9 2.9 0 5.1-1.4 6.6-3.9.7-1.2 1-2.4 1-3.8 0-2.6-1.2-4.6-3.3-6.1-1.3-.9-2.7-1.4-4.2-1.4-3.2 0-5.5 1.6-6.9 4.5-.5 1-.7 2.1-.7 3.1zm32.2-11.3h.5c4.4 0 7.8 2.1 9.9 6 .9 1.5 1.3 3.2 1.3 5v.8c0 4.1-2 7.4-5.6 9.5-1.7 1-3.5 1.5-5.4 1.5H37c-4.1 0-7.4-2-9.5-5.6-1-1.7-1.5-3.5-1.5-5.4v-.8c0-4.1 2.1-7.4 5.6-9.5 1.7-1.1 3.6-1.5 5.5-1.5zm-7.2 11.3c0 2.9 1.4 5 3.9 6.5 1.2.7 2.4 1 3.8 1 2.9 0 5-1.5 6.5-3.9.7-1.2 1-2.4 1-3.8 0-2.7-1.3-4.8-3.5-6.3-1.2-.8-2.6-1.2-4-1.2-3.2 0-5.5 1.6-6.9 4.5-.6 1.1-.8 2.2-.8 3.2zm34.8-7.2c-.6-.3-1.7-.4-2.3-.4-3.2-.1-5.5 1.7-6.9 4.5-.5 1-.7 2-.7 3.1 0 3.3 1.7 5.6 4.6 7 1.1.5 2.4.6 3.6.6 1 0 2.5-.6 3.4-1.1l.2-.1h.8c.9.2 1.5.7 1.5 1.7v.4c0 2.3-4.3 2.9-5.9 3-5.7.4-10-2.7-11.6-8.2-.3-.9-.4-1.9-.4-2.9v-.8c0-4.1 2.1-7.4 5.6-9.5 1.7-1 3.5-1.5 5.4-1.5h.8c2 0 3.9.6 5.6 1.7l.1.1.1.1c.2.3.3.6.3 1v.4c0 1-.7 1.5-1.6 1.7H67c-.5 0-1.8-.6-2.3-.8zm12.4 2.6c1.5-1.5 3-3 4.5-4.4.4-.4 2-2.1 2.6-2.1h.8c.9.2 1.5.7 1.5 1.7v.4c0 .6-.7 1.4-1.2 1.8l-2.7 2.7-4.6 4.7c2 2 4 4 5.9 6l1.6 1.7c.2.2.5.4.6.7.2.3.3.6.3.9v.5c-.2.9-.8 1.6-1.7 1.6h-.3c-.6 0-1.3-.7-1.8-1.1-.9-.8-1.8-1.7-2.6-2.6l-2.9-2.9v4.6c0 1.1-.7 1.9-1.8 1.9H75c-1.1 0-1.8-.9-1.8-1.9V88.9c0-1.1.7-1.9 1.8-1.9h.3c1.1 0 1.8.8 1.8 1.9v11.9zm47.6-6.6h.4c1.1 0 1.9.8 1.9 1.9 0 1.6-1.5 2-2.8 2-1.7 0-3.4 1-4.5 2.2-1.5 1.5-2.1 3.3-2.1 5.4v9.2c0 1.1-.7 1.9-1.8 1.9h-.3c-1.1 0-1.8-.9-1.8-1.9v-9.8c0-3.8 1.8-6.8 4.9-9 1.8-1.2 3.9-1.9 6.1-1.9zm-27.1 18.3c1.4.5 3 .4 4.4.2.7-.3 2.6-1.1 3.3-1h.2c.4.2.8.5 1 .9.5 1 .3 2-.7 2.6l-.3.2c-3.6 2.1-7.5 1.8-11.1-.2-1.7-.9-3-2.3-4-4l-.2-.4c-2.3-4-2-8.3.6-12.1.9-1.3 2.1-2.3 3.5-3.1l.5-.3c3.4-2 7.1-1.8 10.6-.1 1.9.9 3.4 2.3 4.5 4.1l.2.3c.8 1.3-.2 2.5-1.2 3.3-1.2.9-2.4 2-3.5 3-2.7 2.2-5.3 4.4-7.8 6.6zm-3.3-2.3l8.5-7.3c1-.8 2-1.7 3-2.6-.8-1-2.1-1.7-3.1-2.1-2.2-.8-4.4-.6-6.4.6-2.6 1.5-3.8 4-3.7 7 0 1.2.4 2.3 1 3.4.2.4.4.7.7 1M73.7 33.7H85v11.5h5.7c2.6 0 5.3-.5 7.8-1.3 1.2-.4 2.6-1 3.8-1.7-1.6-2.1-2.4-4.7-2.6-7.3-.3-3.5.4-8.1 2.8-10.8l1.2-1.4 1.4 1.1c3.6 2.9 6.5 6.8 7.1 11.4 4.3-1.3 9.3-1 13.1 1.2l1.5.9-.8 1.6c-3.2 6.2-9.9 8.2-16.4 7.8-9.8 24.3-31 35.8-56.8 35.8-13.3 0-25.5-5-32.5-16.8l-.1-.2-1-2.1c-2.4-5.2-3.1-10.9-2.6-16.6l.2-1.7h9.6V33.7h11.3V22.4h22.5V11.1h13.5v22.6z" />
                                <path fill="#00AADA"
                                    d="M110.2 37.9c.8-5.9-3.6-10.5-6.4-12.7-3.1 3.6-3.6 13.2 1.3 17.2-2.8 2.4-8.5 4.7-14.5 4.7H18.4c-.6 6.2.5 11.9 3 16.8l.8 1.5c.5.9 1.1 1.7 1.7 2.6 3 .2 5.7.3 8.2.2 4.9-.1 8.9-.7 12-1.7.5-.2.9.1 1.1.5.2.5-.1.9-.5 1.1-.4.1-.8.3-1.3.4-2.4.7-5 1.1-8.3 1.3h-.6c-1.3.1-2.7.1-4.2.1-1.6 0-3.1 0-4.9-.1 6 6.8 15.4 10.8 27.2 10.8 25 0 46.2-11.1 55.5-35.9 6.7.7 13.1-1 16-6.7-4.5-2.6-10.5-1.8-13.9-.1z" />
                                <path fill="#28B8EB"
                                    d="M110.2 37.9c.8-5.9-3.6-10.5-6.4-12.7-3.1 3.6-3.6 13.2 1.3 17.2-2.8 2.4-8.5 4.7-14.5 4.7h-68c-.3 9.5 3.2 16.7 9.5 21 4.9-.1 8.9-.7 12-1.7.5-.2.9.1 1.1.5.2.5-.1.9-.5 1.1-.4.1-.8.3-1.3.4-2.4.7-5.2 1.2-8.5 1.4l-.1-.1c8.5 4.4 20.8 4.3 35-1.1 15.8-6.1 30.6-17.7 40.9-30.9-.2.1-.3.2-.5.2z" />
                                <path fill="#028BB8"
                                    d="M18.5 54.6c.4 3.3 1.4 6.4 2.9 9.3l.8 1.5c.5.9 1.1 1.7 1.7 2.6 3 .2 5.7.3 8.2.2 4.9-.1 8.9-.7 12-1.7.5-.2.9.1 1.1.5.2.5-.1.9-.5 1.1-.4.1-.8.3-1.3.4-2.4.7-5.2 1.2-8.5 1.4h-.4c-1.3.1-2.7.1-4.1.1-1.6 0-3.2 0-4.9-.1 6 6.8 15.5 10.8 27.3 10.8 21.4 0 40-8.1 50.8-26H18.5v-.1z" />
                                <path fill="#019BC6"
                                    d="M23.3 54.6c1.3 5.8 4.3 10.4 8.8 13.5 4.9-.1 8.9-.7 12-1.7.5-.2.9.1 1.1.5.2.5-.1.9-.5 1.1-.4.1-.8.3-1.3.4-2.4.7-5.2 1.2-8.6 1.4 8.5 4.4 20.8 4.3 34.9-1.1 8.5-3.3 16.8-8.2 24.2-14.1H23.3z" />
                                <path fill-rule="evenodd" clip-rule="evenodd" fill="#00ACD3"
                                    d="M28.2 35.5H38v9.8h-9.8v-9.8zm.8.9h.8v8.1H29v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1H32v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm3.1-12.1h9.8V34h-9.8v-9.7zm.8.8h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1z" />
                                <path fill-rule="evenodd" clip-rule="evenodd" fill="#23C2EE"
                                    d="M39.5 35.5h9.8v9.8h-9.8v-9.8zm.8.9h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1z" />
                                <path fill-rule="evenodd" clip-rule="evenodd" fill="#00ACD3"
                                    d="M50.8 35.5h9.8v9.8h-9.8v-9.8zm.8.9h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1H53v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1H56v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1z" />
                                <path fill-rule="evenodd" clip-rule="evenodd" fill="#23C2EE"
                                    d="M50.8 24.3h9.8V34h-9.8v-9.7zm.8.8h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1H53v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1H56v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zM62 35.5h9.8v9.8H62v-9.8zm.9.9h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1z" />
                                <path fill-rule="evenodd" clip-rule="evenodd" fill="#00ACD3"
                                    d="M62 24.3h9.8V34H62v-9.7zm.9.8h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1z" />
                                <path fill-rule="evenodd" clip-rule="evenodd" fill="#23C2EE"
                                    d="M62 13h9.8v9.8H62V13zm.9.8h.8V22h-.8v-8.2zm1.4 0h.8V22h-.8v-8.2zm1.5 0h.8V22h-.8v-8.2zm1.5 0h.8V22h-.8v-8.2zm1.4 0h.8V22h-.8v-8.2zm1.5 0h.8V22h-.8v-8.2z" />
                                <path fill-rule="evenodd" clip-rule="evenodd" fill="#00ACD3"
                                    d="M73.3 35.5h9.8v9.8h-9.8v-9.8zm.8.9h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1h-.8v-8.1zm1.4 0h.8v8.1h-.8v-8.1zm1.5 0h.8v8.1H80v-8.1zm1.5 0h.8v8.1h-.8v-8.1z" />
                                <path fill-rule="evenodd" clip-rule="evenodd" fill="#D4EEF1"
                                    d="M48.6 61.2c1.5 0 2.7 1.2 2.7 2.7 0 1.5-1.2 2.7-2.7 2.7-1.5 0-2.7-1.2-2.7-2.7.1-1.5 1.3-2.7 2.7-2.7" />
                                <path fill-rule="evenodd" clip-rule="evenodd" fill="#3A4D54"
                                    d="M48.6 61.9c.2 0 .5 0 .7.1-.2.1-.4.4-.4.7 0 .4.4.8.8.8.3 0 .6-.2.7-.4.1.2.1.5.1.7 0 1.1-.9 1.9-1.9 1.9-1.1 0-1.9-.9-1.9-1.9 0-1 .9-1.9 1.9-1.9M1 55.6h125.3c-2.7-.7-8.6-1.6-7.7-5.2-5 5.7-16.9 4-20 1.2-3.4 4.9-23 3-24.3-.8-4.2 5-17.3 5-21.5 0-1.4 3.8-21 5.7-24.3.8-3 2.8-15 4.5-20-1.2 1.1 3.5-4.8 4.5-7.5 5.2" />
                                <path fill="#BFDBE0"
                                    d="M55.8 80.6c-6.7-3.2-10.3-7.5-12.4-12.2-2.5.7-5.5 1.2-8.9 1.4-1.3.1-2.7.1-4.1.1-1.7 0-3.4 0-5.2-.1 6.1 6.1 13.7 10.8 27.6 10.9 1-.1 2-.1 3-.1z" />
                                <path fill="#D4EEF1"
                                    d="M45.9 72.7c-.9-1.3-1.8-2.8-2.5-4.3-2.5.7-5.5 1.2-8.9 1.4 2.4 1.3 5.8 2.5 11.4 2.9z" />
                            </svg>
                        </div>
                    </x-slot:logo>
                </x-resource-view>
            </div>
            <h2 class="py-4">Databases</h2>
            <div class="grid justify-start grid-cols-1 gap-4 text-left xl:grid-cols-2">
                <x-resource-view wire="setType('postgresql')">
                    <x-slot:title>PostgreSQL</x-slot>
                    <x-slot:description>
                        PostgreSQL is an object-relational database known for its
                        robustness, advanced features, and strong standards compliance.
                    </x-slot>
                    <x-slot:logo>
                        <img class="w-[4.5rem]
                            aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100"
                            src="{{ asset('svgs/postgres.svg') }}">
                    </x-slot:logo>
                </x-resource-view>
                <x-resource-view wire="setType('redis')">
                    <x-slot:title>Redis</x-slot>
                    <x-slot:description>
                        Redis is an open-source, in-memory data structure store, used as a database, cache, and message
                        broker.
                    </x-slot>
                    <x-slot:logo>
                        <img class="w-[4.5rem]
                            aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100"
                            src="{{ asset('svgs/redis.svg') }}">
                    </x-slot:logo>
                </x-resource-view>
                <x-resource-view wire="setType('mongodb')">
                    <x-slot:title>MongoDB</x-slot>
                    <x-slot:description>
                        MongoDB is a source-available, NoSQL database that uses JSON-like documents with
                        optional schemas.
                    </x-slot>
                    <x-slot:logo>
                        <img class="w-[4.5rem]
                            aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100"
                            src="{{ asset('svgs/mongodb.svg') }}">
                    </x-slot:logo>
                </x-resource-view>
                <x-resource-view wire="setType('mysql')">
                    <x-slot:title>MySQL</x-slot>
                    <x-slot:description>
                        MySQL is a relational database known for its speed, reliability, and
                        flexibility.
                    </x-slot>
                    <x-slot:logo>
                        <img class="w-[4.5rem]
                            aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100"
                            src="{{ asset('svgs/mysql.svg') }}">
                    </x-slot:logo>
                </x-resource-view>
                <x-resource-view wire="setType('mariadb')">
                    <x-slot:title>Mariadb</x-slot>
                    <x-slot:description>
                        MariaDB is a relational database that serves as a drop-in
                        replacement for MySQL.
                    </x-slot>
                    <x-slot:logo>
                        <img class="w-[4.5rem]
                            aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100"
                            src="{{ asset('svgs/mariadb.svg') }}">
                    </x-slot:logo>
                </x-resource-view>

                {{-- <div class="box group" wire="setType('existing-postgresql')">
                    <div class="flex flex-col mx-6">
                        <div class="group-hover:dark:text-white">
                            Backup Existing PostgreSQL
                        </div>
                        <div class="text-xs group-hover:dark:text-white">
                            Schedule a backup of an existing PostgreSQL database.
                        </div>
                    </div>
                </div> --}}
            </div>
            <div class="flex items-center gap-4" wire:init='loadServices'>
                <h2 class="py-4">Services</h2>
                <x-forms.button wire:click="loadServices('force')">Reload List</x-forms.button>
                <input class="input" autofocus wire:model.live.debounce.200ms="search" autofocus
                    placeholder="Search...">
            </div>
            <div class="pb-4 text-xs">Trademarks Policy: The respective trademarks mentioned here are owned by the
                respective
                companies, and use of them does not imply any affiliation or endorsement.</div>
            <div class="grid justify-start grid-cols-1 gap-4 text-left xl:grid-cols-2">
                @if ($loadingServices)
                    <span class="loading loading-xs loading-spinner"></span>
                @else
                    @forelse ($services as $serviceName => $service)
                        @if (data_get($service, 'minversion') && version_compare(config('version'), data_get($service, 'minversion'), '<'))
                            <x-resource-view wire="setType('one-click-service-{{ $serviceName }}')">
                                <x-slot:title> {{ Str::headline($serviceName) }}</x-slot>
                                <x-slot:description>
                                    @if (data_get($service, 'slogan'))
                                        {{ data_get($service, 'slogan') }}
                                    @endif

                                </x-slot>
                                <x-slot:logo>
                                    @if (data_get($service, 'logo'))
                                        <img class="w-[4.5rem]
                                    aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100"
                                            src="{{ asset(data_get($service, 'logo')) }}">
                                    @endif
                                </x-slot:logo>
                                <x-slot:documentation>
                                    {{ data_get($service, 'documentation') }}
                                </x-slot>
                                <x-slot:upgrade>
                                    You need to upgrade Coolify to {{ data_get($service, 'minversion') }} to use this
                                    service.
                                </x-slot>
                            </x-resource-view>
                            {{-- <button class="text-left cursor-not-allowed bg-coolgray-100 box-without-bg" disabled>
                                <div class="flex flex-col mx-6">
                                    <div class="font-bold">
                                        {{ Str::headline($serviceName) }}
                                    </div>
                                    You need to upgrade to {{ data_get($service, 'minversion') }} to use this service.
                                </div>
                            </button> --}}
                        @else
                            <x-resource-view wire="setType('one-click-service-{{ $serviceName }}')">
                                <x-slot:title> {{ Str::headline($serviceName) }}</x-slot>
                                <x-slot:description>
                                    @if (data_get($service, 'slogan'))
                                        {{ data_get($service, 'slogan') }}
                                    @endif
                                </x-slot>
                                <x-slot:logo>
                                    @if (file_exists(public_path(data_get($service, 'logo'))))
                                        <img class="w-[4.5rem]
                                    aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100"
                                            src="{{ asset(data_get($service, 'logo')) }}">
                                    @else
                                        <img class="w-[4.5rem]
                                    aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100"
                                            src="{{ asset('svgs/unknown.svg') }}">
                                    @endif
                                </x-slot:logo>
                                <x-slot:documentation>
                                    {{ data_get($service, 'documentation') }}
                                </x-slot>
                            </x-resource-view>
                            {{-- <button class="text-left box group" wire:loading.attr="disabled"
                                wire:click="setType('one-click-service-{{ $serviceName }}')">
                                <div class="flex flex-col mx-2">
                                    <div class="font-bold dark:text-white group-hover:dark:text-white">
                                        {{ Str::headline($serviceName) }}
                                    </div>
                                    @if (data_get($service, 'slogan'))
                                        <div class="description">
                                            {{ data_get($service, 'slogan') }}
                                        </div>
                                    @endif
                                </div>
                            </button> --}}
                        @endif
                    @empty
                        <div class="w-96">No service found. Please try to reload the list!</div>
                    @endforelse
                @endif
            </div>

        @endif
        @if ($current_step === 'servers')
            <h2>Select a server</h2>
            <div class="pb-5"></div>
            <div class="flex flex-col justify-center gap-4 text-left xl:flex-row xl:flex-wrap">
                @forelse($servers as $server)
                    <div class="w-full lg:w-64 box group" wire:click="setServer({{ $server }})">
                        <div class="flex flex-col mx-6">
                            <div class="font-bold group-hover:dark:text-white">
                                {{ $server->name }}
                            </div>
                            <div class="text-xs group-hover:dark:text-white">
                                {{ $server->description }}</div>
                        </div>
                    </div>
                @empty
                    <div>
                        <div>No validated & reachable servers found. <a class="underline dark:text-white"
                                href="/servers">
                                Go to servers page
                            </a></div>
                    </div>
                @endforelse
            </div>
            {{-- @if ($isDatabase)
                <div class="text-center">Swarm clusters are excluded from this type of resource at the moment. It will
                    be activated soon. Stay tuned.</div>
            @endif --}}
        @endif
        @if ($current_step === 'destinations')
            <h2>Select a destination</h2>
            <div>Destinations are used to segregate resources by network. If you are unsure, select the default
                Standalone Docker (coolify).</div>
            <div class="flex flex-col justify-center gap-4 text-left xl:flex-row xl:flex-wrap">
                @if ($server->isSwarm())
                    @foreach ($swarmDockers as $swarmDocker)
                        <div class="box group" wire:click="setDestination('{{ $swarmDocker->uuid }}')">
                            <div class="flex flex-col mx-6">
                                <div class="font-bold group-hover:dark:text-white">
                                    Swarm Docker <span class="text-xs">({{ $swarmDocker->name }})</span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                @else
                    @foreach ($standaloneDockers as $standaloneDocker)
                        <div class="box group" wire:click="setDestination('{{ $standaloneDocker->uuid }}')">
                            <div class="flex flex-col mx-6">
                                <div class="font-bold group-hover:dark:text-white">
                                    Standalone Docker <span class="text-xs">({{ $standaloneDocker->name }})</span>
                                </div>
                                <div class="text-xs group-hover:dark:text-white">
                                    Network: {{ $standaloneDocker->network }}</div>
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>
        @endif
        @if ($current_step === 'existing-postgresql')
            <form wire:submit='addExistingPostgresql' class="flex items-end gap-4">
                <x-forms.input placeholder="postgres://username:password@database:5432" label="Database URL"
                    id="existingPostgresqlUrl" />
                <x-forms.button type="submit">Add Database</x-forms.button>
            </form>
        @endif
    </div>
</div>
