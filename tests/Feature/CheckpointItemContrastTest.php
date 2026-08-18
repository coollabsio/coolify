<?php

it('uses the high contrast accent for a running checkpoint spinner', function () {
    $view = $this->blade('<x-checkpoint-item title="Server is reachable" status="running" />');

    $view
        ->assertSee('dark:text-warning', false)
        ->assertSee('animate-spin', false)
        ->assertDontSee('spinner-current', false);
});
