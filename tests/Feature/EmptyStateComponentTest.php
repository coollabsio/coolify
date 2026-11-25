<?php

it('renders action content from both contents and actions slots', function () {
    $contents = $this->blade(
        '<x-empty title="Empty" icon-name="keys"><x-slot:contents><button type="button">From contents</button></x-slot:contents></x-empty>'
    );
    $contents->assertSee('From contents');

    $actions = $this->blade(
        '<x-empty title="Empty" icon-name="keys"><x-slot:actions><button type="button">From actions</button></x-slot:actions></x-empty>'
    );
    $actions->assertSee('From actions');
});
