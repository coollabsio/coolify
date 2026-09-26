<?php

use App\Enums\Role;

it('ranks roles in ascending order of privilege', function () {
    expect(Role::MEMBER->rank())->toBeLessThan(Role::OPERATOR->rank())
        ->and(Role::OPERATOR->rank())->toBeLessThan(Role::ADMIN->rank())
        ->and(Role::ADMIN->rank())->toBeLessThan(Role::OWNER->rank());
});

it('compares operator against other roles', function () {
    expect(Role::OPERATOR->lt(Role::ADMIN))->toBeTrue()
        ->and(Role::OPERATOR->lt(Role::OWNER))->toBeTrue()
        ->and(Role::OPERATOR->gt(Role::MEMBER))->toBeTrue()
        ->and(Role::OPERATOR->gt('admin'))->toBeFalse()
        ->and(Role::from('operator'))->toBe(Role::OPERATOR);
});
