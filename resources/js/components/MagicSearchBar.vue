<template>
    <div class="flex justify-center">
        <div class="flex flex-col">
            <input @focus="toggle" @blur="toggleAndClear" v-model="search" class="w-96" placeholder="🪄 Let the magic happen... Just type...">
            <div v-if="menuOpen" class="absolute text-sm top-11 w-[25rem] bg-neutral-800">
                <div class="py-2 pl-4 cursor-pointer hover:bg-neutral-700" v-for="(item, index) in filteredItems(items)">
                    {{ item.name }}
                </div>
            </div>
        </div>
    </div>
</template>

<script>

export default {
    data() {
        return {
            menuOpen : false,
            search: '',
            selectedMenus: '',
            items: [{
                name: 'Public Repository',
                tags: 'application,public,repository',
                tab: 'public-repo',
            }, {
                name: 'Private Repository (with GitHub App)',
                tags: 'application,private,repository',
                tab: 'github-private-repo-app'
            }, {
                name: 'Private Repository (with Deploy Key)',
                tags: 'application,private,repository',
                tab: 'github-private-repo-deploy-key'
            }, {
                name: 'Database',
                tags: 'data,database,mysql,postgres,sql,sqlite,redis,mongodb,maria,percona',
                tab: 'database'
            }],
        }
    },
    methods: {
        toggle() {
            this.menuOpen = !this.menuOpen
        },
        toggleAndClear() {
            this.menuOpen = false
            this.search = ''
        },
        filteredItems(items) {
            console.log(items)
            return items.filter(item => {
                return item.tags.toLowerCase().includes(this.search.toLowerCase()) || item.name.toLowerCase().includes(this.search.toLowerCase())
            })
        }
    }
}
</script>
