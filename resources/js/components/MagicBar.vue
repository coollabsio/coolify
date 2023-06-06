<template>
    <Transition name="fade">
        <div class="z-10">
            <div class="flex items-center p-1 px-2 overflow-hidden transition-all transform rounded cursor-pointer bg-coolgray-200"
                @click="showCommandPalette = true">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 icon" viewBox="0 0 24 24" stroke-width="2"
                    stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                    <path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" />
                    <path d="M21 21l-6 -6" />
                </svg>
                <span class="px-2 ml-2 text-xs border border-dashed rounded border-neutral-700 text-warning">/</span>
            </div>
            <div class="relative" role="dialog" aria-modal="true" v-if="showCommandPalette" @keyup.esc="resetState">
                <div class="fixed inset-0 transition-opacity bg-opacity-75 bg-coolgray-100" @click.self="resetState">
                </div>
                <div class="fixed inset-0 w-3/5 p-4 mx-auto overflow-y-auto sm:p-6 md:px-20 min-w-fit"
                    @click.self="resetState">
                    <div class="overflow-hidden transition-all transform bg-coolgray-200 ring-1 ring-black ring-opacity-5">
                        <div class="relative">
                            <svg class="absolute w-5 h-5 text-gray-400 pointer-events-none left-3 top-2.5"
                                viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z"
                                    clip-rule="evenodd" />
                            </svg>
                            <input type="text" v-model="search" ref="searchInput"
                                class="w-full h-10 pr-4 text-white rounded outline-none bg-coolgray-400 pl-11 placeholder:text-neutral-700 sm:text-sm focus:outline-none"
                                placeholder="Search, jump or create... magically... 🪄" role="combobox"
                                aria-expanded="false" aria-controls="options">
                        </div>

                        <ul class="px-4 pb-2 overflow-y-auto max-h-80 scroll-py-10 scroll-pb-2 scrollbar" id="options"
                            role="listbox">
                            <li v-if="state.showNew">
                                <ul class="mt-2 -mx-4 text-sm text-white ">
                                    <li class="flex items-center px-4 py-2 cursor-pointer select-none group hover:bg-coolgray-400"
                                        id="option-1" role="option" tabindex="-1" @click="next('redirect', -1, state.icon)">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24"
                                            stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                            stroke-linejoin="round">
                                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                            <path d="M12 5l0 14" />
                                            <path d="M5 12l14 0" />
                                        </svg>
                                        <span class="flex-auto ml-3 truncate">Add new {{ state.icon }}: <span
                                                class="text-xs text-warning" v-if="search">{{ search }}</span>
                                            <span v-else class="text-xs text-warning">with random name (or type
                                                one)</span></span>
                                    </li>
                                </ul>
                            </li>
                            <li>
                                <ul v-if="data.length == 0" class="mt-2 -mx-4 text-sm text-white">
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
                                <h2 v-if="data.length != 0 && state.title"
                                    class="mt-4 mb-2 text-xs font-semibold text-neutral-500">{{
                                        state.title }}
                                </h2>
                                <ul class="mt-2 -mx-4 text-sm text-white">
                                    <li class="flex items-center px-4 py-2 cursor-pointer select-none group hover:bg-coolgray-400"
                                        id="option-1" role="option" tabindex="-1" v-for="action, index in data"
                                        @click="next(state.next ?? action.next, index, action.newAction)">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 icon" viewBox="0 0 24 24"
                                            stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                            stroke-linejoin="round">
                                            <template v-if="action.icon === 'git' || state.icon === 'git'">
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
                                            <template v-if="action.icon === 'server' || state.icon === 'server'">
                                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                                <path
                                                    d="M3 4m0 3a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v2a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3z" />
                                                <path d="M15 20h-9a3 3 0 0 1 -3 -3v-2a3 3 0 0 1 3 -3h12" />
                                                <path d="M7 8v.01" />
                                                <path d="M7 16v.01" />
                                                <path d="M20 15l-2 3h3l-2 3" />
                                            </template>
                                            <template v-if="action.icon === 'destination' || state.icon === 'destination'">
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
                                            <template v-if="action.icon === 'project' || state.icon === 'project'">
                                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                                <path
                                                    d="M9 4h3l2 2h5a2 2 0 0 1 2 2v7a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-9a2 2 0 0 1 2 -2" />
                                                <path d="M17 17v2a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-9a2 2 0 0 1 2 -2h2" />
                                            </template>
                                            <template v-if="action.icon === 'environment' || state.icon === 'environment'">
                                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                                <path d="M16 5l3 3l-2 1l4 4l-3 1l4 4h-9" />
                                                <path d="M15 21l0 -3" />
                                                <path d="M8 13l-2 -2" />
                                                <path d="M8 12l2 -2" />
                                                <path d="M8 21v-13" />
                                                <path
                                                    d="M5.824 16a3 3 0 0 1 -2.743 -3.69a3 3 0 0 1 .304 -4.833a3 3 0 0 1 4.615 -3.707a3 3 0 0 1 4.614 3.707a3 3 0 0 1 .305 4.833a3 3 0 0 1 -2.919 3.695h-4z" />
                                            </template>
                                        </svg>
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

const showCommandPalette = ref(false)
const search = ref()
const searchInput = ref()
const baseUrl = '/magic'
let selected = {};

const appActions = [{
    id: 0,
    name: 'Public Repository',
    tags: 'application,public,repository,github,gitlab,bitbucket,git',
    icon: 'git',
    next: 'server'
},
{
    id: 1,
    name: 'Private Repository (with GitHub Apps)',
    tags: 'application,private,repository,github,gitlab,bitbucket,git',
    icon: 'git',
    next: 'server'
},
{
    id: 2,
    name: 'Private Repository (with Deploy Key)',
    tags: 'application,private,repository,github,gitlab,bitbucket,git',
    icon: 'git',
    next: 'server'
},
{
    id: 3,
    name: 'Servers',
    tags: 'server,new',
    icon: 'server',
    next: 'server',
}
]
const initialState = {
    title: null,
    icon: null,
    next: null,
    current: null,
    showNew: false,
    data: appActions
}
const state = ref({ ...initialState })

const data = computed(() => {
    if (search?.value) {
        return state.value.data.filter(item => item.name.toLowerCase().includes(search.value?.toLowerCase() ?? ''))
    }
    return state.value.data
})

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

function resetState() {
    showCommandPalette.value = false
    state.value = { ...initialState }
    selected = {}
    search.value = ''
}
async function next(nextAction, index, newAction = null) {
    if (newAction) {
        let targetUrl = new URL(window.location.origin)
        let newUrl = new URL(`${window.location.origin}${baseUrl}/${newAction}/new`);
        if (search.value) newUrl.searchParams.append('name', search.value)
        switch (newAction) {
            case 'server':
                targetUrl.pathname = '/server/new'
                window.location.href = targetUrl.href
                break;
            case 'destination':
                targetUrl.pathname = '/destination/new'
                window.location.href = targetUrl.href
                break;
            case 'project':
                const { data: { new_project_uuid, new_project_id } } = await axios(newUrl.href)
                selected.project = new_project_uuid
                await getEnvironments(new_project_id)
                state.value.title = 'Select an Environment'
                state.value.icon = 'environment'
                break;
            case 'environment':
                if (selected.project) newUrl.searchParams.append('project_uuid', selected.project)
                const { data: { new_environment_name } } = await axios(newUrl.href)
                selected.environment = new_environment_name
                await redirect();
                break;
        }
    } else {
        if (state.value.current) {
            if (state.value.current === 'environment') {
                selected[state.value.current] = state.value.data[index].name
            } else {
                selected[state.value.current] = state.value.data[index].uuid
            }
        }
        else selected['action'] = appActions[index].id

        switch (nextAction) {
            case 'server':
                await getServers(true)
                state.value.title = 'Select a server'
                state.value.icon = 'server'
                state.value.showNew = true
                break;
            case 'destination':
                await getDestinations(state.value.data[index].id)
                state.value.title = 'Select a destination'
                state.value.icon = 'destination'
                state.value.showNew = true
                break;
            case 'project':
                await getProjects()
                state.value.title = 'Select a project'
                state.value.icon = 'project'
                state.value.showNew = true
                break;
            case 'environment':
                await getEnvironments(state.value.data[index].id)
                state.value.title = 'Select an environment'
                state.value.icon = 'environment'
                state.value.showNew = true
                break;
            case 'redirect':
                await redirect();
                state.value.showNew = false
                break;
            default:
                break;
        }
    }
    search.value = ''
    searchInput.value.focus()
}

async function redirect() {
    let targetUrl = new URL(window.location.origin)
    switch (selected.action) {
        case 0:
            targetUrl.pathname = `/project/${selected.project}/${selected.environment}/new`
            targetUrl.searchParams.append('type', 'public')
            targetUrl.searchParams.append('destination', selected.destination)
            break;
        case 1:
            targetUrl.pathname = `/project/${selected.project}/${selected.environment}/new`
            targetUrl.searchParams.append('type', 'private-gh-app')
            targetUrl.searchParams.append('destination', selected.destination)
            break;
        case 2:
            targetUrl.pathname = `/project/${selected.project}/${selected.environment}/new`
            targetUrl.searchParams.append('type', 'private-deploy-key')
            targetUrl.searchParams.append('destination', selected.destination)
            break;
        case 3:
            targetUrl.pathname = `/server/${selected.server}/`
            break;
    }
    window.location.href = targetUrl;
}
async function getServers(isJump = false) {
    const { data } = await axios.get(`${baseUrl}/servers`);
    state.value.data = data.servers
    state.value.current = 'server'
    if (isJump) {
        state.value.next = 'redirect'
    } else {
        state.value.next = 'destination'
    }
}
async function getDestinations(serverId) {
    const { data } = await axios.get(`${baseUrl}/destinations?server_id=${serverId}`);
    state.value.data = data.destinations
    state.value.current = 'destination'
    state.value.next = 'project'
}
async function getProjects() {
    const { data } = await axios.get(`${baseUrl}/projects`);
    state.value.data = data.projects
    state.value.current = 'project'
    state.value.next = 'environment'
}
async function getEnvironments(projectId) {
    const { data } = await axios.get(`${baseUrl}/environments?project_id=${projectId}`);
    state.value.data = data.environments
    state.value.current = 'environment'
    state.value.next = 'redirect'
}
</script>
