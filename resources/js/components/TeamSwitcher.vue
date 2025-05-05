<script setup lang="ts">
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { ChevronsUpDown, Plus, Loader2, Settings } from 'lucide-vue-next';
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const teams = page.props.teams as any[];
const currentTeam = page.props.currentTeam as any;
const emit = defineEmits<{
    (e: 'update:teams', teams: any[]): void;
    (e: 'update:currentTeam', team: any): void;
}>();

const { isMobile, state } = useSidebar();
const isLoading = ref(false);

const fetchTeams = async () => {
    if (!isLoading.value && (!teams || teams.length === 0)) {
        isLoading.value = true;
        await router.reload({ only: ['teams', 'currentTeam'] });
        isLoading.value = false;
    }
};

const switchTeam = async (teamId: number) => {
    if (currentTeam && teamId === currentTeam.id) {
        return;
    }

    isLoading.value = true;
    try {
        await router.put(route('current-team.update'), { team_id: teamId }, {
            preserveScroll: true,
            preserveState: true,
        });
    } catch (error) {
        console.error('Failed to switch team:', error);
    } finally {
        isLoading.value = false;
    }
};

const navigateToTeamSettings = (e: Event, teamId: number) => {
    e.stopPropagation(); // Prevent team switching when clicking settings
    router.get(route('teams.show', teamId));
};

const hasTeams = () => teams && teams.length > 0;
const hasCurrentTeam = () => currentTeam !== undefined && currentTeam !== null;

const teamInitial = () => hasCurrentTeam() ? currentTeam!.name.charAt(0).toUpperCase() : '?';
const teamName = () => hasCurrentTeam() ? currentTeam!.name : 'Select Team';
const teamRole = () => hasCurrentTeam() ? currentTeam!.role : '';
</script>

<template>
    <SidebarMenu class="pb-2">
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child @click="fetchTeams">
                    <SidebarMenuButton size="lg"
                        class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground group-data-[collapsible=icon]:mx-auto">
                        <div
                            class="flex aspect-square size-7 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground group-data-[collapsible=icon]:mx-auto">
                            {{ teamInitial() }}
                        </div>
                        <div class="grid flex-1 text-left text-sm leading-tight group-data-[collapsible=icon]:hidden">
                            <span class="truncate font-semibold">
                                {{ teamName() }}
                            </span>
                            <span v-if="hasCurrentTeam()" class="truncate text-xs capitalize">{{ teamRole() }}</span>
                        </div>
                        <ChevronsUpDown class="ml-auto group-data-[collapsible=icon]:hidden" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent class="w-[--reka-dropdown-menu-trigger-width] min-w-56 rounded-lg"
                    :side="isMobile ? 'bottom' : state === 'collapsed' ? 'left' : 'right'" align="start"
                    :side-offset="4">
                    <DropdownMenuLabel class="text-xs text-muted-foreground">
                        Teams
                    </DropdownMenuLabel>
                    <div v-if="isLoading" class="p-4 text-center">
                        <Loader2 class="mx-auto size-4 animate-spin" />
                    </div>
                    <div v-else-if="!hasTeams()" class="p-4 text-center text-sm text-muted-foreground">
                        No teams available
                    </div>
                    <template v-else>
                        <DropdownMenuItem v-for="(team, index) in teams" :key="team.id" class="gap-2 p-2 mb-1"
                            :class="{ 'bg-sidebar-accent cursor-not-allowed': hasCurrentTeam() && team.id === currentTeam?.id }"
                            @click="switchTeam(team.id)">
                            <div class="flex w-full items-center gap-2">
                                <div class="flex size-6 items-center justify-center rounded-sm border">
                                    {{ team.name.charAt(0).toUpperCase() }}
                                </div>
                                <div class="flex-1">
                                    {{ team.name }}
                                </div>
                                <button variant="ghost" size="icon"
                                    class="ml-2 rounded-md p-1 hover:bg-accent hover:text-accent-foreground hover:cursor-pointer"
                                    @click.stop="navigateToTeamSettings($event, team.id)">
                                    <Settings class="size-4" />
                                </button>
                            </div>
                        </DropdownMenuItem>
                    </template>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem as-child>
                        <Link :href="route('dashboard')">
                        <Plus class="mr-2 size-4" />
                        Create Team
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>