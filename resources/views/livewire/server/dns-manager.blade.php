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
        <div class="pb-2 text-sm dark:text-gray-400">Manage hostname, /etc/hosts entries, DNS resolvers, DNS lookups and propagation checks for this server.</div>

        @if($isLoading)
            <div class="flex items-center gap-2">
                <x-loading />
                <span>Loading DNS configuration...</span>
            </div>
        @else
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

            {{-- DNS Lookup Section --}}
            <div class="p-4 border rounded-lg dark:bg-coolgray-100 dark:border-coolgray-200">
                <h3 class="mb-4 text-lg font-semibold">DNS Lookup</h3>
                <div class="mb-3 text-sm dark:text-gray-400">Query DNS records for any domain using <code>dig</code> from this server.</div>

                <div class="flex items-end gap-2">
                    <div class="flex-1">
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
                    <div class="flex-1">
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
                <h3 class="mb-4 text-lg font-semibold">DNS Zone Records</h3>
                <div class="mb-3 text-sm dark:text-gray-400">Query all DNS records for a domain. Shows A, AAAA, CNAME, MX, TXT, NS, SOA, CAA, and SRV records.</div>

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
        @endif
    </div>
</div>
