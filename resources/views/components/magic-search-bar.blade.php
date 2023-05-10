@props(['data' => []])
<div x-data="magicsearchbar">
    <input x-model="search" class="w-96" x-on:click="open = true" x-on:click.outside="close"
        placeholder="🪄 Add / find anything" />
    <div x-cloak x-show="open" class="absolute text-sm top-11 w-[25rem] bg-neutral-800">
        <template x-for="item in filteredItems" :key="item.name">
            <div x-on:click="execute(item.action)" class="py-2 pl-4 cursor-pointer hover:bg-neutral-700">
                <span class="px-2 mr-1 text-xs bg-purple-700 rounded" x-show="item.type" x-text="item.type"></span>
                <span x-text="item.name"></span>
            </div>
        </template>
    </div>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        const data = @js($data);
        console.log(data)
        Alpine.data('magicsearchbar', () => ({
            open: false,
            search: '',
            items: [{
                name: 'Public Repository',
                type: 'add',
                tags: 'application,public,repository',
                action: 'public-repo',
            }, {
                name: 'Private Repository (with GitHub App)',
                type: 'add',
                tags: 'application,private,repository',
                action: 'github-private-repo-app'
            }, {
                name: 'Private Repository (with Deploy Key)',
                type: 'add',
                tags: 'application,private,repository',
                action: 'github-private-repo-deploy-key'
            }, {
                name: 'Database',
                type: 'add',
                tags: 'data,database,mysql,postgres,sql,sqlite,redis,mongodb,maria,percona',
                action: 'database'
            }],
            close() {
                this.open = false
                this.search = ''
            },
            filteredItems() {
                if (this.search === '') return this.items
                return this.items.filter(item => {
                    return item.name.toLowerCase().includes(this.search.toLowerCase())
                })
            },
            execute(action) {
                switch (action) {
                    case 'public-repo':
                        window.location.href = '/project/new/public-repository'
                        break
                    case 'github-private-repo-app':
                        window.location.href = '/project/new/github-private-repository'
                        break
                    case 'github-private-repo-deploy-key':
                        window.location.href = '/project/new/github-private-repository-deploy-key'
                        break
                    case 'database':
                        window.location.href = '/database/new'
                        break
                }
            }
        }))
    })
</script>
