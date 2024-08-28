<div>
    <h2>Danger Zone</h2>
    <div class="">Woah. I hope you know what are you doing.</div>
    <h4 class="pt-4">Delete Resource</h4>
    <div class="pb-4">This will stop your containers, delete all related data, etc. Beware! There is no coming
        back!
    </div>
    <x-modal-confirmation 
        isError
        type="button"
        buttonTitle="Delete this resource" 
        :checkboxes="[
            ['id' => 'delete_volumes', 'model' => 'delete_volumes', 'label' => 'Permanently delete associated volumes?'],
            ['id' => 'delete_connected_networks', 'model' => 'delete_connected_networks', 'label' => 'Permanently delete connected networks, predefined networks are not deleted?'],
            ['id' => 'delete_configurations', 'model' => 'delete_configurations', 'label' => 'Permanently delete configuration files from the server?'],
            ['id' => 'docker_cleanup', 'model' => 'docker_cleanup', 'label' => 'Run Docker cleanup (remove builder cache and unused images)?']
        ]"
        :checkboxActions="[
            'delete_volumes' => $delete_volumes ? 'All associated volumes of this resource will be deleted.' : null,
            'delete_connected_networks' => $delete_connected_networks ? 'All connected networks of this resource will be deleted (predefined networks are not deleted).' : null,
            'delete_configurations' => $delete_configurations ? 'All configuration files of this resource will be deleted on the server.' : null,
            'docker_cleanup' => $docker_cleanup ? 'Docker cleanup will be executed which removes builder cache and unused images.' : null
        ]"
    >
        This resource will be deleted. It is not reversible. <strong class="text-error">Please think again.</strong><br><br>
    </x-modal-confirmation>
</div>
