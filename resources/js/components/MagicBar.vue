<template>
    <Transition name="fade">
        <div>
            <div class="flex items-center p-1 px-2 overflow-hidden transition-all transform rounded cursor-pointer bg-coolgray-100"
                @click="showCommandPalette = true">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 icon" viewBox="0 0 24 24" stroke-width="2"
                    stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                    <path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" />
                    <path d="M21 21l-6 -6" />
                </svg>
                <span class="flex-1"></span>
                <span class="ml-2 kbd-custom">/</span>
            </div>
            <div class="relative" role="dialog" aria-modal="true" v-if="showCommandPalette" @keyup.esc="resetState">
                <div class="fixed inset-0 transition-opacity bg-opacity-90 bg-coolgray-100" @click.self="resetState">
                </div>
                <div class="fixed inset-0 p-4 mx-auto overflow-y-auto lg:w-[70rem] sm:p-10 md:px-20"
                    @click.self="resetState">
                    <div class="overflow-hidden transition-all transform bg-coolgray-200 ring-1 ring-black ring-opacity-5">
                        <div class="relative">
                            <svg class="absolute w-5 h-5 text-gray-400 pointer-events-none left-3 top-2.5"
                                viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z"
                                    clip-rule="evenodd" />
                            </svg>
                            <input type="text" v-model="search" ref="searchInput" @keydown.down="focusNext(magic.length)"
                                @keydown.up="focusPrev(magic.length)" @keyup.enter="callAction"
                                class="w-full h-10 pr-4 rounded outline-none dark:text-white bg-coolgray-400 pl-11 placeholder:text-neutral-700 sm:text-sm focus:outline-none"
                                placeholder="Search, jump or create... magically... 🪄" role="combobox"
                                aria-expanded="false" aria-controls="options">
                        </div>

                        <ul class="px-4 pb-2 overflow-y-auto max-h-96 scroll-py-10 scroll-pb-2 scrollbar" id="options"
                            role="listbox">
                            <li v-if="sequenceState.sequence.length !== 0">
                                <h2 v-if="sequenceState.sequence[sequenceState.currentActionIndex] && possibleSequences[sequenceState.sequence[sequenceState.currentActionIndex]]"
                                    class="mt-4 mb-2 text-xs font-semibold text-neutral-500">{{
                                        possibleSequences[sequenceState.sequence[sequenceState.currentActionIndex]].newTitle }}
                                </h2>
                                <ul class="mt-2 -mx-4 dark:text-white">
                                    <li class="flex items-center px-4 py-2 cursor-pointer select-none group hover:bg-coolgray-400"
                                        id="option-1" role="option" tabindex="-1"
                                        @click="addNew(sequenceState.sequence[sequenceState.currentActionIndex])">
                                        <svg xmlns=" http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24"
                                            stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                            stroke-linejoin="round">
                                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                            <path d="M12 5l0 14" />
                                            <path d="M5 12l14 0" />
                                        </svg>
                                        <span class="flex-auto ml-3 truncate">
                                            <span v-if="search"><span class="capitalize ">{{
                                                sequenceState.sequence[sequenceState.currentActionIndex] }}</span> name
                                                will be:
                                                <span class="inline-block dark:text-warning">{{ search }}</span>
                                            </span>
                                            <span v-else><span class="capitalize ">{{
                                                sequenceState.sequence[sequenceState.currentActionIndex] }}</span> name
                                                will be:
                                                <span class="inline-block dark:text-warning">randomly generated (type to
                                                    change)</span>
                                            </span>
                                        </span>
                                    </li>
                                </ul>
                            </li>
                            <li>
                                <ul v-if="magic.length == 0" class="mt-2 -mx-4 dark:text-white">
                                    <li class="flex items-center px-4 py-2 select-none group">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 icon" viewBox="0 0 24 24"
                                            stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                            stroke-linejoin="round">
                                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                            <path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" />
                                            <path d="M9 10l.01 0" />
                                            <path d="M15 10l.01 0" />
                                            <path d="M9 15l6 0" />
                                        </svg>
                                        <span class="flex-auto ml-3 truncate">Nothing found. Ooops.</span>
                                    </li>
                                </ul>
                                <h2 v-if="magic.length !== 0 && sequenceState.sequence[sequenceState.currentActionIndex] && possibleSequences[sequenceState.sequence[sequenceState.currentActionIndex]]"
                                    class="mt-4 mb-2 text-xs font-semibold text-neutral-500">{{
                                        possibleSequences[sequenceState.sequence[sequenceState.currentActionIndex]].title }}
                                </h2>
                                <ul v-if="magic.length != 0" class="mt-2 -mx-4 dark:text-white">
                                    <li class="flex items-center px-4 py-2 transition-all cursor-pointer select-none group hover:bg-coolgray-400"
                                        :class="{ 'bg-coollabs': currentFocus === index }" id="option-1" role="option"
                                        tabindex="-1" v-for="action, index in magic" @click="goThroughSequence(index)"
                                        ref="magicItems">
                                        <div class="relative">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 icon" viewBox="0 0 24 24"
                                                stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                                stroke-linejoin="round">
                                                <template
                                                    v-if="action.icon === 'git' || sequenceState.sequence[sequenceState.currentActionIndex] === 'git'">
                                                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                                    <path d="M16 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
                                                    <path d="M12 8m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
                                                    <path d="M12 16m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
                                                    <path d="M12 15v-6" />
                                                    <path d="M15 11l-2 -2" />
                                                    <path d="M11 7l-1.9 -1.9" />
                                                    <path
                                                        d="M13.446 2.6l7.955 7.954a2.045 2.045 0 0 1 0 2.892l-7.955 7.955a2.045 2.045 0 0 1 -2.892 0l-7.955 -7.955a2.045 2.045 0 0 1 0 -2.892l7.955 -7.955a2.045 2.045 0 0 1 2.892 0z" />
                                                </template>
                                                <template
                                                    v-if="action.icon === 'server' || sequenceState.sequence[sequenceState.currentActionIndex] === 'server'">
                                                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                                    <path
                                                        d="M3 4m0 3a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v2a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3z" />
                                                    <path d="M15 20h-9a3 3 0 0 1 -3 -3v-2a3 3 0 0 1 3 -3h12" />
                                                    <path d="M7 8v.01" />
                                                    <path d="M7 16v.01" />
                                                    <path d="M20 15l-2 3h3l-2 3" />
                                                </template>
                                                <template
                                                    v-if="action.icon === 'destination' || sequenceState.sequence[sequenceState.currentActionIndex] === 'destination'">
                                                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                                    <path
                                                        d="M22 12.54c-1.804 -.345 -2.701 -1.08 -3.523 -2.94c-.487 .696 -1.102 1.568 -.92 2.4c.028 .238 -.32 1 -.557 1h-14c0 5.208 3.164 7 6.196 7c4.124 .022 7.828 -1.376 9.854 -5c1.146 -.101 2.296 -1.505 2.95 -2.46z" />
                                                    <path d="M5 10h3v3h-3z" />
                                                    <path d="M8 10h3v3h-3z" />
                                                    <path d="M11 10h3v3h-3z" />
                                                    <path d="M8 7h3v3h-3z" />
                                                    <path d="M11 7h3v3h-3z" />
                                                    <path d="M11 4h3v3h-3z" />
                                                    <path d="M4.571 18c1.5 0 2.047 -.074 2.958 -.78" />
                                                    <path d="M10 16l0 .01" />
                                                </template>
                                                <template
                                                    v-if="action.icon === 'storage' || sequenceState.sequence[sequenceState.currentActionIndex] === 'storage'">
                                                    <g fill="none" stroke="currentColor" stroke-linecap="round"
                                                        stroke-linejoin="round" stroke-width="2">
                                                        <path d="M4 6a8 3 0 1 0 16 0A8 3 0 1 0 4 6" />
                                                        <path d="M4 6v6a8 3 0 0 0 16 0V6" />
                                                        <path d="M4 12v6a8 3 0 0 0 16 0v-6" />
                                                    </g>
                                                </template>
                                                <template
                                                    v-if="action.icon === 'project' || sequenceState.sequence[sequenceState.currentActionIndex] === 'project'">
                                                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                                    <path
                                                        d="M9 4h3l2 2h5a2 2 0 0 1 2 2v7a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-9a2 2 0 0 1 2 -2" />
                                                    <path
                                                        d="M17 17v2a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-9a2 2 0 0 1 2 -2h2" />
                                                </template>
                                                <template
                                                    v-if="action.icon === 'environment' || sequenceState.sequence[sequenceState.currentActionIndex] === 'environment'">
                                                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                                    <path d="M16 5l3 3l-2 1l4 4l-3 1l4 4h-9" />
                                                    <path d="M15 21l0 -3" />
                                                    <path d="M8 13l-2 -2" />
                                                    <path d="M8 12l2 -2" />
                                                    <path d="M8 21v-13" />
                                                    <path
                                                        d="M5.824 16a3 3 0 0 1 -2.743 -3.69a3 3 0 0 1 .304 -4.833a3 3 0 0 1 4.615 -3.707a3 3 0 0 1 4.614 3.707a3 3 0 0 1 .305 4.833a3 3 0 0 1 -2.919 3.695h-4z" />
                                                </template>
                                                <template
                                                    v-if="action.icon === 'key' || sequenceState.sequence[sequenceState.currentActionIndex] === 'key'">
                                                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                                    <path d="M14 10m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />
                                                    <path d="M21 12a9 9 0 1 1 -18 0a9 9 0 0 1 18 0z" />
                                                    <path d="M12.5 11.5l-4 4l1.5 1.5" />
                                                    <path d="M12 15l-1.5 -1.5" />
                                                </template>
                                                <template
                                                    v-if="action.icon === 'goto' || sequenceState.sequence[sequenceState.currentActionIndex] === 'goto'">
                                                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                                    <path d="M10 18h4" />
                                                    <path
                                                        d="M3 8a9 9 0 0 1 9 9v1l1.428 -4.285a12 12 0 0 1 6.018 -6.938l.554 -.277" />
                                                    <path d="M15 6h5v5" />
                                                </template>
                                                <template
                                                    v-if="action.icon === 'team' || sequenceState.sequence[sequenceState.currentActionIndex] === 'team'">
                                                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                                    <path d="M10 13a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                                                    <path d="M8 21v-1a2 2 0 0 1 2 -2h4a2 2 0 0 1 2 2v1" />
                                                    <path d="M15 5a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                                                    <path d="M17 10h2a2 2 0 0 1 2 2v1" />
                                                    <path d="M5 5a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                                                    <path d="M3 13v-1a2 2 0 0 1 2 -2h2" />
                                                </template>
                                            </svg>
                                            <div v-if="action.new"
                                                class="absolute top-0 right-0 -mt-2 -mr-2 font-bold dark:text-warning">+
                                            </div>
                                        </div>
                                        <span class="flex-auto ml-3 truncate">{{ action.name }}</span>
                                    </li>
                                </ul>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </Transition>
</template>
<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import axios from "axios";
const currentFocus = ref(0)
const magicItems = ref()
function focusNext(length) {
    if (currentFocus.value === undefined) {
        currentFocus.value = 0
    } else {
        if (length > currentFocus.value + 1) {
            currentFocus.value = currentFocus.value + 1
        }
    }
    if (currentFocus.value > 4) {
        magicItems.value[currentFocus.value].scrollIntoView({ block: "center", inline: "center", behavior: 'auto' })
    }
}
function focusPrev(length) {
    if (currentFocus.value === undefined) {
        currentFocus.value = length - 1
    } else {
        if (currentFocus.value > 0) {
            currentFocus.value = currentFocus.value - 1
        }
    }
    if (currentFocus.value < length - 4) {
        magicItems.value[currentFocus.value].scrollIntoView({ block: "center", inline: "center", behavior: 'auto' })
    }
}
async function callAction() {
    await goThroughSequence(currentFocus.value)
}
const showCommandPalette = ref(false)
const search = ref()
const searchInput = ref()

const baseUrl = '/magic'

const uuidSelector = ['project', 'destination']
const nameSelector = ['environment']
const possibleSequences = {
    server: {
        newTitle: 'Create a new Server',
        title: 'Select a server'
    },
    destination: {
        newTitle: 'Create a new Destination',
        title: 'Select a destination'
    },
    project: {
        newTitle: 'Create a new Project',
        title: 'Select a project'
    },
    environment: {
        newTitle: 'Create a new Environment',
        title: 'Select an environment'
    },
}
const magicActions = [{
    id: 1,
    name: 'Deploy: Public Repository',
    tags: 'git,github,public',
    icon: 'git',
    new: true,
    sequence: ['main', 'server', 'destination', 'project', 'environment', 'redirect']
},
{
    id: 2,
    name: 'Deploy: Private Repository (with GitHub Apps)',
    tags: 'git,github,private',
    icon: 'git',
    new: true,
    sequence: ['main', 'server', 'destination', 'project', 'environment', 'redirect']
},
{
    id: 3,
    name: 'Deploy: Private Repository (with Deploy Key)',
    tags: 'git,github,private,deploy,key',
    icon: 'git',
    new: true,
    sequence: ['main', 'server', 'destination', 'project', 'environment', 'redirect']
},
{
    id: 4,
    name: 'Deploy: Dockerfile',
    tags: 'dockerfile,deploy',
    icon: 'destination',
    new: true,
    sequence: ['main', 'server', 'destination', 'project', 'environment', 'redirect']
},
{
    id: 5,
    name: 'Create: Server',
    tags: 'server,ssh,new,create',
    icon: 'server',
    new: true,
    sequence: ['main', 'redirect']
},
{
    id: 6,
    name: 'Create: Source',
    tags: 'source,git,gitlab,github,bitbucket,gitea,new,create',
    icon: 'git',
    new: true,
    sequence: ['main', 'redirect']
},
{
    id: 7,
    name: 'Create: Private Key',
    tags: 'private,key,ssh,new,create',
    icon: 'key',
    new: true,
    sequence: ['main', 'redirect']
},
{
    id: 8,
    name: 'Create: Destination',
    tags: 'destination,docker,network,new,create',
    icon: 'destination',
    new: true,
    sequence: ['main', 'server', 'redirect']
},
{
    id: 9,
    name: 'Create: Team',
    tags: 'team,member,new,create',
    icon: 'team',
    new: true,
    sequence: ['main', 'redirect']
},
{
    id: 10,
    name: 'Create: S3 Storage',
    tags: 's3,storage,new,create',
    icon: 'storage',
    new: true,
    sequence: ['main', 'redirect']
},
{
    id: 11,
    name: 'Goto: S3 Storage',
    tags: 's3,storage',
    icon: 'goto',
    sequence: ['main', 'redirect']
},
{
    id: 12,
    name: 'Goto: Dashboard',
    icon: 'goto',
    sequence: ['main', 'redirect']
},
{
    id: 13,
    name: 'Goto: Servers',
    icon: 'goto',
    sequence: ['main', 'redirect']
},
{
    id: 14,
    name: 'Goto: Private Keys',
    tags: 'destination,docker,network,new,create,ssh,private,key',
    icon: 'goto',
    sequence: ['main', 'redirect']
},
{
    id: 15,
    name: 'Goto: Projects',
    icon: 'goto',
    sequence: ['main', 'redirect']
},
{
    id: 16,
    name: 'Goto: Sources',
    icon: 'goto',
    sequence: ['main', 'redirect']
},
{
    id: 17,
    name: 'Goto: Destinations',
    icon: 'goto',
    sequence: ['main', 'redirect']
},
{
    id: 18,
    name: 'Goto: Settings',
    icon: 'goto',
    sequence: ['main', 'redirect']
},
{
    id: 19,
    name: 'Goto: Command Center',
    icon: 'goto',
    sequence: ['main', 'redirect']
},
{
    id: 20,
    name: 'Goto: Notifications',
    icon: 'goto',
    sequence: ['main', 'redirect']
},
{
    id: 21,
    name: 'Goto: Profile',
    icon: 'goto',
    sequence: ['main', 'redirect']
},
{
    id: 22,
    name: 'Goto: Teams',
    icon: 'goto',
    sequence: ['main', 'redirect']
},
{
    id: 23,
    name: 'Goto: Switch Teams',
    icon: 'goto',
    sequence: ['main', 'redirect']
},
{
    id: 24,
    name: 'Goto: Onboarding process',
    icon: 'goto',
    sequence: ['main', 'redirect']
},
{
    id: 25,
    name: 'Goto: API Tokens',
    tags: 'api,tokens,rest',
    icon: 'goto',
    sequence: ['main', 'redirect']
},
{
    id: 26,
    name: 'Goto: Team Shared Variables',
    tags: 'team,shared,variables',
    icon: 'goto',
    sequence: ['main', 'redirect']
}
]
const initialState = {
    sequence: [],
    currentActionIndex: 0,
    magicActions,
    selected: {}
}
const sequenceState = ref({ ...initialState })

function focusSearch(event) {
    if (event.target.nodeName === 'BODY') {
        if (event.key === '/') {
            event.preventDefault();
            showCommandPalette.value = true;
        }
    }
}

onMounted(() => {
    window.addEventListener("keydown", focusSearch);
})
onUnmounted(() => {
    window.removeEventListener("keydown", focusSearch);
})

watch(showCommandPalette, async (value) => {
    if (value) {
        await nextTick();
        searchInput.value.focus();
    }
})
watch(search, async () => {
    currentFocus.value = 0
})
const magic = computed(() => {
    if (search.value) {
        return sequenceState.value.magicActions.filter(action => {
            return action.name.toLowerCase().includes(search.value.toLowerCase()) || action.tags?.toLowerCase().includes(search.value.toLowerCase())
        })
    }
    return sequenceState.value.magicActions
})
async function addNew(name) {
    let targetUrl = new URL(window.location.origin)
    let newUrl = new URL(`${window.location.origin}${baseUrl}/${name}/new`);
    if (search.value) {
        targetUrl.searchParams.append('name', search.value)
        newUrl.searchParams.append('name', search.value)
    }
    switch (name) {
        case 'server':
            targetUrl.pathname = '/server/new'
            window.location.href = targetUrl.href
            break;
        case 'destination':
            targetUrl.pathname = '/destination/new'
            window.location.href = targetUrl.href
            break;
        case 'project':
            const { data: { project_uuid } } = await axios(newUrl.href)
            search.value = ''
            sequenceState.value.selected['project'] = project_uuid
            sequenceState.value.magicActions = await getEnvironments(project_uuid)
            sequenceState.value.currentActionIndex += 1
            break;
        case 'environment':
            newUrl.searchParams.append('project_uuid', sequenceState.value.selected.project)
            const { data: { environment_name } } = await axios(newUrl.href)
            search.value = ''
            sequenceState.value.selected['environment'] = environment_name
            redirect()
            break;
    }
}
function resetState() {
    showCommandPalette.value = false
    sequenceState.value = { ...initialState }
    search.value = ''
}
async function goThroughSequence(actionId) {
    let currentSequence = null;
    let nextSequence = null;
    if (sequenceState.value.selected.main === undefined) {
        const { sequence, id } = magic.value[actionId];
        currentSequence = sequence[sequenceState.value.currentActionIndex]
        nextSequence = sequence[sequenceState.value.currentActionIndex + 1]
        sequenceState.value.sequence = sequence
        sequenceState.value.selected = {
            main: id
        }
    } else {
        currentSequence = sequenceState.value.sequence[sequenceState.value.currentActionIndex]
        nextSequence = sequenceState.value.sequence[sequenceState.value.currentActionIndex + 1]
        let selectedId = sequenceState.value.magicActions[actionId].id
        if (uuidSelector.includes(currentSequence)) {
            selectedId = sequenceState.value.magicActions[actionId].uuid
        }
        if (nameSelector.includes(currentSequence)) {
            selectedId = sequenceState.value.magicActions[actionId].name
        }
        sequenceState.value.selected = {
            ...sequenceState.value.selected,
            [currentSequence]: selectedId
        }
    }
    switch (nextSequence) {
        case 'server':
            sequenceState.value.magicActions = await getServers();
            break;
        case 'destination':
            sequenceState.value.magicActions = await getDestinations(sequenceState.value.selected[currentSequence]);
            break;
        case 'project':
            sequenceState.value.magicActions = await getProjects()
            break;
        case 'environment':
            sequenceState.value.magicActions = await getEnvironments(sequenceState.value.selected[currentSequence])
            break;
        case 'redirect':
            redirect()
            break;
        default:
            break;
    }
    sequenceState.value.currentActionIndex += 1
    search.value = ''
    searchInput.value.focus()
    currentFocus.value = 0
}
async function getServers() {
    const { data: { servers } } = await axios.get(`${baseUrl}/servers`);
    return servers;
}
async function getDestinations(serverId) {
    const { data: { destinations } } = await axios.get(`${baseUrl}/destinations?server_id=${serverId}`);
    return destinations;
}
async function getProjects() {
    const { data: { projects } } = await axios.get(`${baseUrl}/projects`);
    return projects;
}
async function getEnvironments(project_uuid) {
    const { data: { environments } } = await axios.get(`${baseUrl}/environments?project_uuid=${project_uuid}`);
    return environments;
}

async function redirect() {
    let targetUrl = new URL(window.location.origin)
    const selected = sequenceState.value.selected
    const { main, destination = null, project = null, environment = null, server = null } = selected
    switch (main) {
        case 1:
            targetUrl.pathname = `/project/${project}/${environment}/new`
            targetUrl.searchParams.append('type', 'public')
            targetUrl.searchParams.append('destination', destination)
            break;
        case 2:
            targetUrl.pathname = `/project/${project}/${environment}/new`
            targetUrl.searchParams.append('type', 'private-gh-app')
            targetUrl.searchParams.append('destination', destination)
            break;
        case 3:
            targetUrl.pathname = `/project/${project}/${environment}/new`
            targetUrl.searchParams.append('type', 'private-deploy-key')
            targetUrl.searchParams.append('destination', destination)
            break;
        case 4:
            targetUrl.pathname = `/project/${project}/${environment}/new`
            targetUrl.searchParams.append('type', 'dockerfile')
            targetUrl.searchParams.append('destination', destination)
            break;
        case 5:
            targetUrl.pathname = `/server/new`
            break;
        case 6:
            targetUrl.pathname = `/source/new`
            break;
        case 7:
            targetUrl.pathname = `/security/private-key/new`
            break;
        case 8:
            targetUrl.pathname = `/destination/new`
            targetUrl.searchParams.append('server', server)
            break;
        case 9:
            targetUrl.pathname = `/team/new`
            break;
        case 10:
            targetUrl.pathname = `/team/storages/new`
            break;
        case 11:
            targetUrl.pathname = `/team/storages/`
            break;
        case 12:
            targetUrl.pathname = `/`
            break;
        case 13:
            targetUrl.pathname = `/servers`
            break;
        case 14:
            targetUrl.pathname = `/security/private-key`
            break;
        case 15:
            targetUrl.pathname = `/projects`
            break;
        case 16:
            targetUrl.pathname = `/sources`
            break;
        case 17:
            targetUrl.pathname = `/destinations`
            break;
        case 18:
            targetUrl.pathname = `/settings`
            break;
        case 19:
            targetUrl.pathname = `/command-center`
            break;
        case 20:
            targetUrl.pathname = `/team/notifications`
            break;
        case 21:
            targetUrl.pathname = `/profile`
            break;
        case 22:
            targetUrl.pathname = `/team`
            break;
        case 23:
            targetUrl.pathname = `/team`
            break;
        case 24:
            targetUrl.pathname = `/onboarding`
            break;
        case 25:
            targetUrl.pathname = `/security/api-tokens`
            break;
        case 26:
            targetUrl.pathname = `/team/shared-variables`
            break;
    }
    window.location.href = targetUrl;
}
</script>
