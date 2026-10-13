<?php

it('renders a self-contained clipboard button for backend-provided values', function () {
    $html = $this->blade('<x-copy-button value="backup/path.sql" label="Copy backup path" />');

    $html->assertSee('Copy backup path')
        ->assertSee('backup\/path.sql', false)
        ->assertSee('x-data="copyButton"', false)
        ->assertDontSee('window.copyToClipboard', false);
});

it('disables the button when no backend value is available', function () {
    $html = $this->blade('<x-copy-button :value="null" />');

    $html->assertSee('disabled', false);
});

it('evaluates a resolve expression at click time instead of a static value', function () {
    $html = $this->blade('<x-copy-button resolve="$wire.copyValue()" />');

    $html->assertSee('await ($wire.copyValue())', false)
        ->assertDontSee('disabled', false);
});
