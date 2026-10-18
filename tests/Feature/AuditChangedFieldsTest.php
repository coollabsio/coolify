<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Builds an unsaved model whose stored state is the given raw attributes.
 *
 * @param  array<string, mixed>  $attributes
 */
function auditChangedFieldsModel(array $attributes): Model
{
    $model = new class extends Model
    {
        protected $casts = [
            'secret' => 'encrypted',
            'labels' => 'array',
        ];
    };
    $model->setRawAttributes($attributes, true);

    return $model;
}

it('ignores timestamps and loosely equal values', function () {
    $model = auditChangedFieldsModel(['flag' => 0, 'name' => 'web', 'updated_at' => '2026-01-01 00:00:00']);

    $model->flag = false;
    $model->name = 'web';
    $model->updated_at = '2026-02-01 00:00:00';

    expect(auditChangedFields($model))->toBe([]);

    $model->flag = true;
    $model->name = 'api';

    expect(auditChangedFields($model))->toBe(['flag', 'name']);
});

it('compares decrypted values of encrypted attributes', function () {
    $model = auditChangedFieldsModel(['secret' => Crypt::encryptString('token')]);

    $model->secret = 'token';
    expect(auditChangedFields($model))->toBe([]);

    $model->secret = 'other';
    expect(auditChangedFields($model))->toBe(['secret']);
});

it('compares decoded values of array attributes', function () {
    $model = auditChangedFieldsModel(['labels' => json_encode(['linux', 'x64'])]);

    $model->labels = ['linux', 'x64'];
    expect(auditChangedFields($model))->toBe([]);

    $model->labels = ['linux', 'arm64'];
    expect(auditChangedFields($model))->toBe(['labels']);
});
