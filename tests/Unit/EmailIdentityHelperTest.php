<?php

it('normalizes plus and dotted email identity variants', function () {
    expect(normalize_email_identity('Ke.VinMcFadden+one@BTInternet.com'))->toBe('kevinmcfadden@btinternet.com');
    expect(normalize_email_identity('k.e.v.i.n.m.c.f.a.d.d.e.n+two@btinternet.com'))->toBe('kevinmcfadden@btinternet.com');
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
