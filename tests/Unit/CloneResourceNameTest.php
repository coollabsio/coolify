<?php

it('adds a clone suffix to the source name', function () {
    expect(clone_resource_name('Gitea Runner', 'abc123def456ghi789jkl012'))
        ->toBe('Gitea Runner-clone-abc123def456ghi789jkl012');
});

it('replaces the clone suffixes of earlier clones', function () {
    $name = 'Gitea Runner-clone-sy2cojnseswr8szrpj1omsgw-clone-yvj9kdc2xuff8td0f0mps7eq-clone-ab12cd3';

    expect(clone_resource_name($name, 'newid000000000000000000a'))
        ->toBe('Gitea Runner-clone-newid000000000000000000a');
});

it('keeps a name that only contains the word clone', function () {
    expect(clone_resource_name('my-clone-db', 'abc123def456ghi789jkl012'))
        ->toBe('my-clone-db-clone-abc123def456ghi789jkl012');
});

it('keeps the clone name within the 255 characters of the name column', function () {
    $name = clone_resource_name(str_repeat('a', 255), 'abc123def456ghi789jkl012');

    expect($name)->toHaveLength(255)
        ->toEndWith('-clone-abc123def456ghi789jkl012');
});
