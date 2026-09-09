<?php

it('normalizes plus and dotted email identity variants', function () {
    expect(normalize_email_identity('Ke.VinMcFadden+one@Gmail.com'))->toBe('kevinmcfadden@gmail.com');
    expect(normalize_email_identity('k.e.v.i.n.m.c.f.a.d.d.e.n+two@googlemail.com'))->toBe('kevinmcfadden@googlemail.com');
});

it('preserves dots and plus suffixes for ordinary email providers', function () {
    expect(normalize_email_identity(' John.Smith+alerts@Example.com '))->toBe('john.smith+alerts@example.com');
});

it('returns null for blank or malformed email identities', function (?string $email) {
    expect(normalize_email_identity($email))->toBeNull();
})->with([
    null,
    '',
    'not-an-email',
    '@example.com',
    'local@',
]);
