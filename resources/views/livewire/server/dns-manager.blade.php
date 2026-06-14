<div>
    <x-slot:title>
        DNS Manager | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col gap-8">
        <div class="flex items-center gap-2">
            <h2>DNS & Hostname Manager</h2>
            <button wire:click="loadDnsData" class="button">
                <svg class="w-4 h-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2a10.016 10.016 0 0 0-7 2.877V3a1 1 0 1 0-2 0v4.5a1 1 0 0 0 1 1h4.5a1 1 0 0 0 0-2H6.218A7.98 7.98 0 0 1 20 12a1 1 0 0 0 2 0A10.012 10.012 0 0 0 12 2zm7.989 13.5h-4.5a1 1 0 0 0 0 2h2.293A7.98 7.98 0 0 1 4 12a1 1 0 0 0-2 0a9.986 9.986 0 0 0 16.989 7.133V21a1 1 0 0 0 2 0v-4.5a1 1 0 0 0-1-1z"/>
                </svg>
                Refresh
            </button>
        </div>
        <div class="pb-2 text-sm dark:text-gray-400">Manage DNS server, zones, records, hostname, resolvers, lookups and propagation checks for this server.</div>

        {{-- Tab Navigation --}}
        <div class="flex gap-1 border-b dark:border-coolgray-200">
            <button wire:click="switchTab('server-config')"
                class="px-4 py-2 text-sm rounded-t-lg {{ $activeTab === 'server-config' ? 'dark:bg-coolgray-100 dark:text-white font-semibold border border-b-0 dark:border-coolgray-200' : 'dark:text-gray-400 hover:dark:text-white' }}">
                Server Config
            </button>
            <button wire:click="switchTab('dns-server')"
                class="px-4 py-2 text-sm rounded-t-lg {{ $activeTab === 'dns-server' ? 'dark:bg-coolgray-100 dark:text-white font-semibold border border-b-0 dark:border-coolgray-200' : 'dark:text-gray-400 hover:dark:text-white' }}">
                DNS Server
            </button>
            <button wire:click="switchTab('zone-editor')"
                class="px-4 py-2 text-sm rounded-t-lg {{ $activeTab === 'zone-editor' ? 'dark:bg-coolgray-100 dark:text-white font-semibold border border-b-0 dark:border-coolgray-200' : 'dark:text-gray-400 hover:dark:text-white' }}">
                Zone Editor
            </button>
            <button wire:click="switchTab('dns-tools')"
                class="px-4 py-2 text-sm rounded-t-lg {{ $activeTab === 'dns-tools' ? 'dark:bg-coolgray-100 dark:text-white font-semibold border border-b-0 dark:border-coolgray-200' : 'dark:text-gray-400 hover:dark:text-white' }}">
                DNS Tools
            </button>
        </div>

        @if($isLoading)
            <div class="flex items-center gap-2">
                <x-loading />
                <span>Loading DNS configuration...</span>
            </div>
        @else

            {{-- ═══════════════════════════════════════════════════ --}}
            {{-- TAB: SERVER CONFIG                                  --}}
            {{-- ═══════════════════════════════════════════════════ --}}
            @if($activeTab === 'server-config')

                {{-- Hostname Section --}}
                <div class="p-4 border rounded-lg dark:bg-coolgray-100 dark:border-coolgray-200">
                    <h3 class="mb-4 text-lg font-semibold">Hostname</h3>
                    <div class="flex items-end gap-2">
                        <div class="flex-1">
                            <x-forms.input id="hostname" label="Server Hostname"
                                helper="The hostname of this server. Changing this will run 'hostnamectl set-hostname' on the server."
                                placeholder="my-server.example.com" />
                        </div>
                        <x-forms.button wire:click="updateHostname">
                            Update Hostname
                        </x-forms.button>
                    </div>
                    <div class="mt-2 text-sm dark:text-gray-400">
                        Current: <span class="font-mono dark:text-white">{{ $currentHostname }}</span>
                    </div>
                </div>

                {{-- /etc/hosts Section --}}
                <div class="p-4 border rounded-lg dark:bg-coolgray-100 dark:border-coolgray-200">
                    <h3 class="mb-4 text-lg font-semibold">/etc/hosts</h3>

                    @if(count($hostsEntries) > 0)
                        <div class="mb-4 overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left border-b dark:border-coolgray-200">
                                        <th class="pb-2 pr-4">IP Address</th>
                                        <th class="pb-2 pr-4">Hostname(s)</th>
                                        <th class="pb-2 w-20">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($hostsEntries as $index => $entry)
                                        <tr class="border-b dark:border-coolgray-200">
                                            <td class="py-2 pr-4 font-mono">{{ $entry['ip'] }}</td>
                                            <td class="py-2 pr-4 font-mono">{{ $entry['hostnames'] }}</td>
                                            <td class="py-2">
                                                @if(!in_array($entry['ip'], ['127.0.0.1', '::1']))
                                                    <button wire:click="removeHostEntry({{ $index }})"
                                                        wire:confirm="Are you sure you want to remove this hosts entry?"
                                                        class="text-xs text-red-500 hover:text-red-400">
                                                        Remove
                                                    </button>
                                                @else
                                                    <span class="text-xs dark:text-gray-500">System</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="mb-4 text-sm dark:text-gray-400">No hosts entries found.</div>
                    @endif

                    <div class="flex items-end gap-2 pt-2 border-t dark:border-coolgray-200">
                        <div class="flex-1">
                            <x-forms.input id="newHostIp" label="IP Address" placeholder="192.168.1.100" />
                        </div>
                        <div class="flex-1">
                            <x-forms.input id="newHostName" label="Hostname" placeholder="myserver.local" />
                        </div>
                        <x-forms.button wire:click="addHostEntry">
                            Add Entry
                        </x-forms.button>
                    </div>
                </div>

                {{-- DNS Resolvers Section --}}
                <div class="p-4 border rounded-lg dark:bg-coolgray-100 dark:border-coolgray-200">
                    <h3 class="mb-4 text-lg font-semibold">DNS Resolvers (Nameservers)</h3>

                    @if(count($dnsResolvers) > 0)
                        <div class="mb-4 space-y-2">
                            @foreach($dnsResolvers as $index => $resolver)
                                <div class="flex items-center justify-between p-2 rounded dark:bg-coolgray-200">
                                    <span class="font-mono">{{ $resolver }}</span>
                                    <button wire:click="removeDnsResolver({{ $index }})"
                                        wire:confirm="Are you sure you want to remove this DNS resolver?"
                                        class="text-xs text-red-500 hover:text-red-400">
                                        Remove
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="mb-4 text-sm dark:text-gray-400">No DNS resolvers configured.</div>
                    @endif

                    <div class="flex items-end gap-2 pt-2 border-t dark:border-coolgray-200">
                        <div class="flex-1">
                            <x-forms.input id="newDnsResolver" label="DNS Server IP"
                                helper="Common DNS servers: 8.8.8.8 (Google), 1.1.1.1 (Cloudflare), 9.9.9.9 (Quad9)"
                                placeholder="8.8.8.8" />
                        </div>
                        <x-forms.button wire:click="addDnsResolver">
                            Add Resolver
                        </x-forms.button>
                    </div>
                </div>

                {{-- Search Domains Section --}}
                <div class="p-4 border rounded-lg dark:bg-coolgray-100 dark:border-coolgray-200">
                    <h3 class="mb-4 text-lg font-semibold">Search Domains</h3>
                    <div class="mb-2 text-sm dark:text-gray-400">Search domains are appended to short hostnames when resolving. Configured via the <code>search</code> directive in resolv.conf.</div>

                    @if(count($searchDomains) > 0)
                        <div class="mb-4 space-y-2">
                            @foreach($searchDomains as $index => $domain)
                                <div class="flex items-center justify-between p-2 rounded dark:bg-coolgray-200">
                                    <span class="font-mono">{{ $domain }}</span>
                                    <button wire:click="removeSearchDomain({{ $index }})"
                                        wire:confirm="Are you sure you want to remove this search domain?"
                                        class="text-xs text-red-500 hover:text-red-400">
                                        Remove
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="mb-4 text-sm dark:text-gray-400">No search domains configured.</div>
                    @endif

                    <div class="flex items-end gap-2 pt-2 border-t dark:border-coolgray-200">
                        <div class="flex-1">
                            <x-forms.input id="newSearchDomain" label="Search Domain" placeholder="example.com" />
                        </div>
                        <x-forms.button wire:click="addSearchDomain">
                            Add Domain
                        </x-forms.button>
                    </div>
                </div>

                {{-- resolv.conf Section --}}
                <div class="p-4 border rounded-lg dark:bg-coolgray-100 dark:border-coolgray-200">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold">/etc/resolv.conf</h3>
                        <button wire:click="toggleRawEdit" class="text-sm dark:text-gray-400 hover:dark:text-white">
                            {{ $rawEditMode ? 'Cancel Edit' : 'Edit Raw' }}
                        </button>
                    </div>

                    @if($rawEditMode)
                        <div class="space-y-3">
                            <x-forms.textarea id="resolvConfEdit" rows="8"
                                helper="Edit resolv.conf directly. Must contain at least one nameserver entry." />
                            <div class="flex gap-2">
                                <x-forms.button wire:click="saveResolvConf">
                                    Save Changes
                                </x-forms.button>
                                <button wire:click="toggleRawEdit" class="button">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    @else
                        <pre class="p-3 overflow-x-auto text-sm rounded dark:bg-coolgray-200 font-mono">{{ $resolvConfContent }}</pre>
                    @endif
                </div>

            {{-- ═══════════════════════════════════════════════════ --}}
            {{-- TAB: DNS SERVER                                     --}}
            {{-- ═══════════════════════════════════════════════════ --}}
            @elseif($activeTab === 'dns-server')

                {{-- DNS Server Status --}}
                <div class="p-4 border rounded-lg dark:bg-coolgray-100 dark:border-coolgray-200">
                    <h3 class="mb-4 text-lg font-semibold">DNS Server (BIND9)</h3>

                    @if(!$dnsServerInstalled)
                        <div class="space-y-4">
                            <div class="flex items-center gap-3 p-3 rounded dark:bg-coolgray-200">
                                <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
                                <span>BIND9 DNS server is not installed on this server.</span>
                            </div>
                            <div class="text-sm dark:text-gray-400">
                                Installing BIND9 will allow you to host authoritative DNS zones on this server. You can create zones, manage DNS records (A, AAAA, CNAME, MX, TXT, NS, SRV, CAA, PTR), create nameservers, import/export zone files, and more.
                            </div>
                            <x-forms.button wire:click="installDnsServer"
                                wire:confirm="This will install BIND9 DNS server on the target server. Continue?">
                                Install BIND9 DNS Server
                            </x-forms.button>
                        </div>
                    @else
                        <div class="space-y-4">
                            <div class="flex items-center gap-3 p-3 rounded dark:bg-coolgray-200">
                                <div class="w-3 h-3 rounded-full {{ $dnsServerStatus === 'active' ? 'bg-green-500' : 'bg-red-500' }}"></div>
                                <div>
                                    <span class="font-medium">Status: {{ ucfirst($dnsServerStatus) }}</span>
                                    @if(!empty($dnsServerVersion))
                                        <span class="ml-2 text-xs dark:text-gray-400">({{ $dnsServerVersion }})</span>
                                    @endif
                                </div>
                            </div>

                            <div class="flex gap-2">
                                @if($dnsServerStatus !== 'active')
                                    <x-forms.button wire:click="startDnsServer">
                                        Start
                                    </x-forms.button>
                                @else
                                    <x-forms.button wire:click="stopDnsServer"
                                        wire:confirm="Stop DNS server? This will make all hosted zones unreachable.">
                                        Stop
                                    </x-forms.button>
                                @endif
                                <x-forms.button wire:click="restartDnsServer">
                                    Restart
                                </x-forms.button>
                                <button wire:click="detectDnsServer" class="button">
                                    Refresh Status
                                </button>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Zone Management --}}
                @if($dnsServerInstalled)
                    <div class="p-4 border rounded-lg dark:bg-coolgray-100 dark:border-coolgray-200">
                        <h3 class="mb-4 text-lg font-semibold">DNS Zones</h3>

                        @if(count($zones) > 0)
                            <div class="mb-4 overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="text-left border-b dark:border-coolgray-200">
                                            <th class="pb-2 pr-4">Domain</th>
                                            <th class="pb-2 pr-4">Type</th>
                                            <th class="pb-2 pr-4">Zone File</th>
                                            <th class="pb-2 w-48">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($zones as $zone)
                                            <tr class="border-b dark:border-coolgray-200">
                                                <td class="py-2 pr-4 font-mono font-medium">{{ $zone['domain'] }}</td>
                                                <td class="py-2 pr-4">
                                                    <span class="px-2 py-0.5 text-xs rounded {{ $zone['type'] === 'master' ? 'bg-green-500/20 text-green-400' : 'bg-blue-500/20 text-blue-400' }}">
                                                        {{ ucfirst($zone['type']) }}
                                                    </span>
                                                </td>
                                                <td class="py-2 pr-4 font-mono text-xs dark:text-gray-400">{{ $zone['file'] }}</td>
                                                <td class="py-2">
                                                    <div class="flex gap-2">
                                                        <button wire:click="selectZone('{{ $zone['domain'] }}')"
                                                            class="text-xs text-blue-500 hover:text-blue-400">
                                                            Edit Records
                                                        </button>
                                                        <button wire:click="exportZone('{{ $zone['domain'] }}')"
                                                            class="text-xs dark:text-gray-400 hover:dark:text-white">
                                                            Export
                                                        </button>
                                                        <button wire:click="deleteZone('{{ $zone['domain'] }}')"
                                                            wire:confirm="Delete zone {{ $zone['domain'] }}? This will remove the zone and all its records permanently."
                                                            class="text-xs text-red-500 hover:text-red-400">
                                                            Delete
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="mb-4 text-sm dark:text-gray-400">No DNS zones configured. Create one below.</div>
                        @endif

                        {{-- Create Zone Form --}}
                        <div class="pt-4 border-t dark:border-coolgray-200">
                            <h4 class="mb-3 text-sm font-semibold">Create New Zone</h4>
                            <div class="flex flex-wrap items-end gap-2">
                                <div class="flex-1 min-w-[200px]">
                                    <x-forms.input id="newZoneDomain" label="Domain Name" placeholder="example.com" />
                                </div>
                                <div class="w-36">
                                    <x-forms.select id="newZoneType" label="Zone Type">
                                        <option value="master">Master</option>
                                        <option value="slave">Slave</option>
                                    </x-forms.select>
                                </div>
                                @if($newZoneType === 'slave')
                                    <div class="flex-1 min-w-[200px]">
                                        <x-forms.input id="newZoneMasterIp" label="Master Server IP" placeholder="10.0.0.1" />
                                    </div>
                                @else
                                    <div class="flex-1 min-w-[200px]">
                                        <x-forms.input id="newZoneAdminEmail" label="Admin Email (prefix)"
                                            helper="Will be used as admin.domain. in SOA record"
                                            placeholder="admin" />
                                    </div>
                                @endif
                                <x-forms.button wire:click="createZone">
                                    Create Zone
                                </x-forms.button>
                            </div>
                        </div>
                    </div>

                    {{-- Import Zone --}}
                    <div class="p-4 border rounded-lg dark:bg-coolgray-100 dark:border-coolgray-200">
                        <h3 class="mb-4 text-lg font-semibold">Import Zone File</h3>
                        <div class="mb-3 text-sm dark:text-gray-400">Import a BIND zone file. The zone will be validated before being activated.</div>
                        <div class="space-y-3">
                            <div class="w-full max-w-md">
                                <x-forms.input id="importZoneDomain" label="Domain Name" placeholder="example.com" />
                            </div>
                            <x-forms.textarea id="importZoneContent" label="Zone File Content" rows="10"
                                helper="Paste a complete BIND zone file here." />
                            <x-forms.button wire:click="importZone">
                                Validate & Import
                            </x-forms.button>
                        </div>
                    </div>
                @endif

            {{-- ═══════════════════════════════════════════════════ --}}
            {{-- TAB: ZONE EDITOR                                    --}}
            {{-- ═══════════════════════════════════════════════════ --}}
            @elseif($activeTab === 'zone-editor')

                @if(!$dnsServerInstalled)
                    <div class="p-4 text-sm border rounded-lg dark:bg-coolgray-100 dark:border-coolgray-200 dark:text-gray-400">
                        DNS server is not installed. Go to the "DNS Server" tab to install BIND9 first.
                    </div>
                @elseif(empty($selectedZone))
                    <div class="p-4 border rounded-lg dark:bg-coolgray-100 dark:border-coolgray-200">
                        <h3 class="mb-4 text-lg font-semibold">Select a Zone to Edit</h3>
                        @if(count($zones) > 0)
                            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach($zones as $zone)
                                    <button wire:click="selectZone('{{ $zone['domain'] }}')"
                                        class="flex items-center justify-between p-3 text-left rounded dark:bg-coolgray-200 hover:dark:bg-coolgray-300">
                                        <span class="font-mono font-medium">{{ $zone['domain'] }}</span>
                                        <span class="px-2 py-0.5 text-xs rounded {{ $zone['type'] === 'master' ? 'bg-green-500/20 text-green-400' : 'bg-blue-500/20 text-blue-400' }}">
                                            {{ ucfirst($zone['type']) }}
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                        @else
                            <div class="text-sm dark:text-gray-400">No zones configured. Go to "DNS Server" tab to create one.</div>
                        @endif
                    </div>
                @else
                    {{-- Zone Header --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <button wire:click="deselectZone" class="text-sm dark:text-gray-400 hover:dark:text-white">
                                &larr; Back to zones
                            </button>
                            <h3 class="text-lg font-semibold font-mono">{{ $selectedZone }}</h3>
                            @if(!empty($selectedZoneSerial))
                                <span class="text-xs dark:text-gray-400">Serial: {{ $selectedZoneSerial }}</span>
                            @endif
                        </div>
                        <button wire:click="toggleZoneRawEdit" class="text-sm dark:text-gray-400 hover:dark:text-white">
                            {{ $zoneRawEditMode ? 'Visual Editor' : 'Raw Zone File' }}
                        </button>
                    </div>

                    @if($zoneRawEditMode)
                        {{-- Raw Zone File Editor --}}
                        <div class="p-4 border rounded-lg dark:bg-coolgray-100 dark:border-coolgray-200">
                            <h4 class="mb-3 text-sm font-semibold">Zone File Editor</h4>
                            <div class="space-y-3">
                                <x-forms.textarea id="zoneFileContent" rows="20"
                                    helper="Edit the zone file directly. It will be validated with named-checkzone before saving." />
                                <div class="flex gap-2">
                                    <x-forms.button wire:click="saveZoneFile">
                                        Validate & Save
                                    </x-forms.button>
                                    <button wire:click="toggleZoneRawEdit" class="button">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        </div>
                    @else
                        {{-- Visual Records Table --}}
                        <div class="p-4 border rounded-lg dark:bg-coolgray-100 dark:border-coolgray-200">
                            <h4 class="mb-4 text-sm font-semibold">DNS Records</h4>

                            @if(count($selectedZoneRecords) > 0)
                                <div class="mb-4 overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead>
                                            <tr class="text-left border-b dark:border-coolgray-200">
                                                <th class="pb-2 pr-3">Name</th>
                                                <th class="pb-2 pr-3">TTL</th>
                                                <th class="pb-2 pr-3">Type</th>
                                                <th class="pb-2 pr-3">Priority</th>
                                                <th class="pb-2 pr-3">Value</th>
                                                <th class="pb-2 w-32">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($selectedZoneRecords as $index => $record)
                                                @if($editingRecordIndex === $index)
                                                    {{-- Inline Edit Row --}}
                                                    <tr class="border-b dark:border-coolgray-200 dark:bg-coolgray-200/50">
                                                        <td class="py-2 pr-3">
                                                            <input type="text" wire:model="editRecordName"
                                                                class="w-full px-2 py-1 text-xs font-mono rounded dark:bg-coolgray-300 dark:border-coolgray-400 border" />
                                                        </td>
                                                        <td class="py-2 pr-3">
                                                            <input type="number" wire:model="editRecordTtl"
                                                                class="w-20 px-2 py-1 text-xs font-mono rounded dark:bg-coolgray-300 dark:border-coolgray-400 border" />
                                                        </td>
                                                        <td class="py-2 pr-3">
                                                            <span class="px-2 py-0.5 text-xs rounded
                                                                @if($record['type'] === 'A') bg-blue-500/20 text-blue-400
                                                                @elseif($record['type'] === 'AAAA') bg-purple-500/20 text-purple-400
                                                                @elseif($record['type'] === 'CNAME') bg-green-500/20 text-green-400
                                                                @elseif($record['type'] === 'MX') bg-orange-500/20 text-orange-400
                                                                @elseif($record['type'] === 'TXT') bg-yellow-500/20 text-yellow-400
                                                                @elseif($record['type'] === 'NS') bg-cyan-500/20 text-cyan-400
                                                                @elseif($record['type'] === 'SOA') bg-red-500/20 text-red-400
                                                                @elseif($record['type'] === 'SRV') bg-pink-500/20 text-pink-400
                                                                @elseif($record['type'] === 'CAA') bg-emerald-500/20 text-emerald-400
                                                                @else bg-gray-500/20 text-gray-400
                                                                @endif
                                                            ">{{ $record['type'] }}</span>
                                                        </td>
                                                        <td class="py-2 pr-3">
                                                            @if(in_array($record['type'], ['MX', 'SRV']))
                                                                <input type="number" wire:model="editRecordPriority"
                                                                    class="w-16 px-2 py-1 text-xs font-mono rounded dark:bg-coolgray-300 dark:border-coolgray-400 border" />
                                                            @else
                                                                <span class="text-xs dark:text-gray-500">-</span>
                                                            @endif
                                                        </td>
                                                        <td class="py-2 pr-3">
                                                            <input type="text" wire:model="editRecordValue"
                                                                class="w-full px-2 py-1 text-xs font-mono rounded dark:bg-coolgray-300 dark:border-coolgray-400 border" />
                                                        </td>
                                                        <td class="py-2">
                                                            <div class="flex gap-1">
                                                                <button wire:click="updateRecord" class="text-xs text-green-500 hover:text-green-400">Save</button>
                                                                <button wire:click="cancelEditRecord" class="text-xs dark:text-gray-400 hover:dark:text-white">Cancel</button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                @else
                                                    {{-- Normal Display Row --}}
                                                    <tr class="border-b dark:border-coolgray-200">
                                                        <td class="py-2 pr-3 font-mono text-xs">{{ $record['name'] }}</td>
                                                        <td class="py-2 pr-3 font-mono text-xs dark:text-gray-400">{{ $record['ttl'] }}s</td>
                                                        <td class="py-2 pr-3">
                                                            <span class="px-2 py-0.5 text-xs rounded
                                                                @if($record['type'] === 'A') bg-blue-500/20 text-blue-400
                                                                @elseif($record['type'] === 'AAAA') bg-purple-500/20 text-purple-400
                                                                @elseif($record['type'] === 'CNAME') bg-green-500/20 text-green-400
                                                                @elseif($record['type'] === 'MX') bg-orange-500/20 text-orange-400
                                                                @elseif($record['type'] === 'TXT') bg-yellow-500/20 text-yellow-400
                                                                @elseif($record['type'] === 'NS') bg-cyan-500/20 text-cyan-400
                                                                @elseif($record['type'] === 'SOA') bg-red-500/20 text-red-400
                                                                @elseif($record['type'] === 'SRV') bg-pink-500/20 text-pink-400
                                                                @elseif($record['type'] === 'CAA') bg-emerald-500/20 text-emerald-400
                                                                @else bg-gray-500/20 text-gray-400
                                                                @endif
                                                            ">{{ $record['type'] }}</span>
                                                        </td>
                                                        <td class="py-2 pr-3 font-mono text-xs dark:text-gray-400">
                                                            {{ $record['priority'] ?: '-' }}
                                                        </td>
                                                        <td class="py-2 pr-3 font-mono text-xs break-all">{{ $record['value'] }}</td>
                                                        <td class="py-2">
                                                            @if($record['editable'] ?? true)
                                                                <div class="flex gap-2">
                                                                    <button wire:click="startEditRecord({{ $index }})"
                                                                        class="text-xs text-blue-500 hover:text-blue-400">Edit</button>
                                                                    <button wire:click="deleteRecord({{ $index }})"
                                                                        wire:confirm="Delete this {{ $record['type'] }} record?"
                                                                        class="text-xs text-red-500 hover:text-red-400">Delete</button>
                                                                </div>
                                                            @else
                                                                <span class="text-xs dark:text-gray-500">System</span>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endif
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <div class="mb-4 text-sm dark:text-gray-400">No records found in this zone.</div>
                            @endif

                            {{-- Add Record Form --}}
                            <div class="pt-4 border-t dark:border-coolgray-200">
                                <h4 class="mb-3 text-sm font-semibold">Add New Record</h4>
                                <div class="flex flex-wrap items-end gap-2">
                                    <div class="w-40">
                                        <x-forms.input id="newRecordName" label="Name"
                                            helper="Use @ for the zone apex"
                                            placeholder="@ or subdomain" />
                                    </div>
                                    <div class="w-20">
                                        <x-forms.input id="newRecordTtl" label="TTL" type="number" placeholder="3600" />
                                    </div>
                                    <div class="w-28">
                                        <x-forms.select id="newRecordType" label="Type">
                                            @foreach($recordTypes as $type)
                                                <option value="{{ $type }}">{{ $type }}</option>
                                            @endforeach
                                        </x-forms.select>
                                    </div>
                                    @if(in_array($newRecordType, ['MX', 'SRV']))
                                        <div class="w-20">
                                            <x-forms.input id="newRecordPriority" label="Priority" type="number" placeholder="10" />
                                        </div>
                                    @endif
                                    <div class="flex-1 min-w-[200px]">
                                        <x-forms.input id="newRecordValue" label="Value"
                                            placeholder="{{ $newRecordType === 'A' ? '192.168.1.1' : ($newRecordType === 'AAAA' ? '2001:db8::1' : ($newRecordType === 'CNAME' ? 'target.example.com.' : ($newRecordType === 'MX' ? 'mail.example.com.' : ($newRecordType === 'TXT' ? '\"v=spf1 include:example.com ~all\"' : ($newRecordType === 'NS' ? 'ns1.example.com.' : ($newRecordType === 'SRV' ? '0 443 server.example.com.' : ($newRecordType === 'CAA' ? '0 issue \"letsencrypt.org\"' : 'value'))))))) }}" />
                                    </div>
                                    <x-forms.button wire:click="addRecord">
                                        Add Record
                                    </x-forms.button>
                                </div>
                            </div>
                        </div>

                        {{-- Quick Add Templates --}}
                        <div class="p-4 border rounded-lg dark:bg-coolgray-100 dark:border-coolgray-200">
                            <h4 class="mb-3 text-sm font-semibold">Common Record Templates</h4>
                            <div class="text-sm dark:text-gray-400 mb-3">Click to pre-fill the add record form above.</div>
                            <div class="flex flex-wrap gap-2">
                                <button wire:click="$set('newRecordType', 'A'); $set('newRecordName', '@'); $set('newRecordValue', '{{ $server->ip }}')"
                                    class="px-3 py-1.5 text-xs rounded dark:bg-coolgray-200 hover:dark:bg-coolgray-300">
                                    A (Root → Server IP)
                                </button>
                                <button wire:click="$set('newRecordType', 'CNAME'); $set('newRecordName', 'www'); $set('newRecordValue', '@')"
                                    class="px-3 py-1.5 text-xs rounded dark:bg-coolgray-200 hover:dark:bg-coolgray-300">
                                    CNAME (www → @)
                                </button>
                                <button wire:click="$set('newRecordType', 'MX'); $set('newRecordName', '@'); $set('newRecordValue', 'mail.{{ $selectedZone }}.'); $set('newRecordPriority', 10)"
                                    class="px-3 py-1.5 text-xs rounded dark:bg-coolgray-200 hover:dark:bg-coolgray-300">
                                    MX (Mail Server)
                                </button>
                                <button wire:click="$set('newRecordType', 'TXT'); $set('newRecordName', '@'); $set('newRecordValue', '\"v=spf1 a mx ~all\"')"
                                    class="px-3 py-1.5 text-xs rounded dark:bg-coolgray-200 hover:dark:bg-coolgray-300">
                                    TXT (SPF)
                                </button>
                                <button wire:click="$set('newRecordType', 'TXT'); $set('newRecordName', '_dmarc'); $set('newRecordValue', '\"v=DMARC1; p=quarantine; rua=mailto:admin@{{ $selectedZone }}\"')"
                                    class="px-3 py-1.5 text-xs rounded dark:bg-coolgray-200 hover:dark:bg-coolgray-300">
                                    TXT (DMARC)
                                </button>
                                <button wire:click="$set('newRecordType', 'CAA'); $set('newRecordName', '@'); $set('newRecordValue', '0 issue \"letsencrypt.org\"')"
                                    class="px-3 py-1.5 text-xs rounded dark:bg-coolgray-200 hover:dark:bg-coolgray-300">
                                    CAA (Let's Encrypt)
                                </button>
                                <button wire:click="$set('newRecordType', 'NS'); $set('newRecordName', '@'); $set('newRecordValue', 'ns1.{{ $selectedZone }}.')"
                                    class="px-3 py-1.5 text-xs rounded dark:bg-coolgray-200 hover:dark:bg-coolgray-300">
                                    NS (Nameserver)
                                </button>
                                <button wire:click="$set('newRecordType', 'A'); $set('newRecordName', 'ns1'); $set('newRecordValue', '{{ $server->ip }}')"
                                    class="px-3 py-1.5 text-xs rounded dark:bg-coolgray-200 hover:dark:bg-coolgray-300">
                                    A (ns1 Glue Record)
                                </button>
                            </div>
                        </div>
                    @endif

            {{-- ═══════════════════════════════════════════════════ --}}
            {{-- TAB: DNS TOOLS                                      --}}
            {{-- ═══════════════════════════════════════════════════ --}}
            @elseif($activeTab === 'dns-tools')

                {{-- DNS Lookup Section --}}
                <div class="p-4 border rounded-lg dark:bg-coolgray-100 dark:border-coolgray-200">
                    <h3 class="mb-4 text-lg font-semibold">DNS Lookup</h3>
                    <div class="mb-3 text-sm dark:text-gray-400">Query DNS records for any domain using <code>dig</code> from this server.</div>

                    <div class="flex flex-wrap items-end gap-2">
                        <div class="flex-1 min-w-[200px]">
                            <x-forms.input id="lookupDomain" label="Domain" placeholder="example.com" />
                        </div>
                        <div class="w-32">
                            <x-forms.select id="lookupType" label="Type">
                                @foreach($recordTypes as $type)
                                    <option value="{{ $type }}">{{ $type }}</option>
                                @endforeach
                                <option value="ANY">ANY</option>
                            </x-forms.select>
                        </div>
                        <div class="flex-1 min-w-[200px]">
                            <x-forms.input id="lookupServer" label="DNS Server (optional)" placeholder="8.8.8.8" />
                        </div>
                        <x-forms.button wire:click="dnsLookup">
                            Lookup
                        </x-forms.button>
                    </div>

                    @if(!empty($lookupResult))
                        <div class="mt-4">
                            <pre class="p-3 overflow-x-auto text-sm rounded dark:bg-coolgray-200 font-mono whitespace-pre-wrap">{{ $lookupResult }}</pre>
                        </div>
                    @endif
                </div>

                {{-- DNS Zone Records Section --}}
                <div class="p-4 border rounded-lg dark:bg-coolgray-100 dark:border-coolgray-200">
                    <h3 class="mb-4 text-lg font-semibold">DNS Zone Query (External)</h3>
                    <div class="mb-3 text-sm dark:text-gray-400">Query all DNS records for a domain from public DNS. Shows A, AAAA, CNAME, MX, TXT, NS, SOA, CAA, and SRV records.</div>

                    <div class="flex items-end gap-2 mb-4">
                        <div class="flex-1">
                            <x-forms.input id="zoneDomain" label="Domain" placeholder="example.com" />
                        </div>
                        <x-forms.button wire:click="queryZoneRecords">
                            Query Records
                        </x-forms.button>
                    </div>

                    @if(count($zoneRecords) > 0)
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left border-b dark:border-coolgray-200">
                                        <th class="pb-2 pr-4">Name</th>
                                        <th class="pb-2 pr-4">Type</th>
                                        <th class="pb-2 pr-4">TTL</th>
                                        <th class="pb-2 pr-4">Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($zoneRecords as $record)
                                        <tr class="border-b dark:border-coolgray-200">
                                            <td class="py-2 pr-4 font-mono text-xs">{{ $record['name'] }}</td>
                                            <td class="py-2 pr-4">
                                                <span class="px-2 py-0.5 text-xs rounded
                                                    @if($record['type'] === 'A') bg-blue-500/20 text-blue-400
                                                    @elseif($record['type'] === 'AAAA') bg-purple-500/20 text-purple-400
                                                    @elseif($record['type'] === 'CNAME') bg-green-500/20 text-green-400
                                                    @elseif($record['type'] === 'MX') bg-orange-500/20 text-orange-400
                                                    @elseif($record['type'] === 'TXT') bg-yellow-500/20 text-yellow-400
                                                    @elseif($record['type'] === 'NS') bg-cyan-500/20 text-cyan-400
                                                    @elseif($record['type'] === 'SOA') bg-red-500/20 text-red-400
                                                    @elseif($record['type'] === 'SRV') bg-pink-500/20 text-pink-400
                                                    @elseif($record['type'] === 'CAA') bg-emerald-500/20 text-emerald-400
                                                    @else bg-gray-500/20 text-gray-400
                                                    @endif
                                                ">{{ $record['type'] }}</span>
                                            </td>
                                            <td class="py-2 pr-4 font-mono text-xs dark:text-gray-400">{{ $record['ttl'] }}s</td>
                                            <td class="py-2 pr-4 font-mono text-xs break-all">
                                                @if(isset($record['priority']))
                                                    <span class="dark:text-gray-400">pri:{{ $record['priority'] }}</span>
                                                @endif
                                                {{ $record['value'] }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                {{-- DNS Propagation Check Section --}}
                <div class="p-4 border rounded-lg dark:bg-coolgray-100 dark:border-coolgray-200">
                    <h3 class="mb-4 text-lg font-semibold">DNS Propagation Check</h3>
                    <div class="mb-3 text-sm dark:text-gray-400">Check if DNS records have propagated across major public DNS servers worldwide.</div>

                    <div class="flex items-end gap-2 mb-4">
                        <div class="flex-1">
                            <x-forms.input id="propagationDomain" label="Domain" placeholder="example.com" />
                        </div>
                        <div class="w-32">
                            <x-forms.select id="propagationType" label="Type">
                                @foreach($recordTypes as $type)
                                    <option value="{{ $type }}">{{ $type }}</option>
                                @endforeach
                            </x-forms.select>
                        </div>
                        <x-forms.button wire:click="checkPropagation">
                            Check Propagation
                        </x-forms.button>
                    </div>

                    @if(count($propagationResults) > 0)
                        <div class="space-y-2">
                            @foreach($propagationResults as $result)
                                <div class="flex items-center justify-between p-3 rounded dark:bg-coolgray-200">
                                    <div class="flex items-center gap-3">
                                        <div class="w-3 h-3 rounded-full {{ $result['status'] === 'success' ? 'bg-green-500' : 'bg-red-500' }}"></div>
                                        <div>
                                            <span class="font-medium">{{ $result['server'] }}</span>
                                            <span class="ml-2 text-xs dark:text-gray-400">({{ $result['ip'] }})</span>
                                        </div>
                                    </div>
                                    <span class="font-mono text-sm">{{ $result['result'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

            @endif

        @endif
    </div>
</div>
