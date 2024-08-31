<div>
    <h2>Danger Zone</h2>
    <div class="">Woah. I hope you know what are you doing.</div>
    <h4 class="pt-4">Delete Resource</h4>
    <div class="pb-4">This will stop your containers, delete all related data, etc. Beware! There is no coming back!</div>
    <x-modal-confirmation 
        title="Confirm Resource Deletion?"
        buttonTitle="Delete Resource"
        isErrorButton
        type="button" 
        submitAction="delete" 
        buttonTitle="Delete Resource" 
        :checkboxes="[
            ['id' => 'delete_volumes', 'model' => 'delete_volumes', 'label' => 'All associated volumes with this resource will be permanently deleted'],
            ['id' => 'delete_connected_networks', 'model' => 'delete_connected_networks', 'label' => 'All connected networks with this resource will be permanently deleted (predefined networks will not be deleted)'],
            ['id' => 'delete_configurations', 'model' => 'delete_configurations', 'label' => 'All configuration files will be permanently deleted form the server'],
            ['id' => 'docker_cleanup', 'model' => 'docker_cleanup', 'label' => 'Docker cleanup will be run on the server which removes builder cache and unused images']
        ]" 
        :actions="[
            'All containers of this resource will be stopped and permanently deleted.'
        ]" 
        confirmationText="{{ $resourceName }}"
        confirmationLabel="Please confirm the execution of the actions by entering the Resource Name below"
        shortConfirmationLabel="Resource Name"
        step3ButtonText="Permanently Delete Resource"
    />
</div>
