<?php

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;

it('generates RSA keys that GitHub accepts (at least 3072 bits)', function () {
    $keyPair = generateSSHKey('rsa');

    $privateKey = PublicKeyLoader::load($keyPair['private']);
    $publicKey = PublicKeyLoader::load($keyPair['public']);

    expect($privateKey)->toBeInstanceOf(RSA\PrivateKey::class)
        ->and($privateKey->getLength())->toBeGreaterThanOrEqual(3072)
        ->and($publicKey->getLength())->toBe($privateKey->getLength());
});
