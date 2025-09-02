<div>
    <x-modal wire:model="showModal">
        <x-modal.content title="Container File Browser - {{ $application->name }}">
            <div class="container-file-browser">
                <!-- Security Warning -->
                <div class="alert alert-warning mb-4">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Security Notice:</strong> You have full access to container files. 
                    Be careful when modifying system files or sensitive configurations.
                </div>

                <!-- Vue.js File Browser Component -->
                <div id="file-browser-app">
                    <file-browser :container-id="'{{ $application->uuid }}'"></file-browser>
                </div>
            </div>
        </x-modal.content>
    </x-modal>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Vue.js app when modal opens
            const initFileBrowser = () => {
                if (typeof Vue !== 'undefined' && document.getElementById('file-browser-app')) {
                    new Vue({
                        el: '#file-browser-app',
                        components: {
                            'file-browser': window.FileBrowser
                        }
                    });
                }
            };

            // Initialize on modal show
            Livewire.on('modal-opened', () => {
                setTimeout(initFileBrowser, 100);
            });
        });
    </script>

    <style>
        .container-file-browser {
            min-height: 600px;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid;
            margin-bottom: 1rem;
        }

        .alert-warning {
            background-color: #fff3cd;
            border-color: #ffeaa7;
            color: #856404;
        }

        .alert i {
            margin-right: 0.5rem;
        }
    </style>
</div>