<?php

namespace App\Http\Controllers\V5\Concerns;

use Illuminate\Http\Request;

trait ValidatesBuilderConfiguration
{
    /**
     * @return array<int, string>
     */
    protected function builderCapacityRules(bool $builderEnabled, bool $required = false): array
    {
        return [
            $required ? 'required' : 'sometimes',
            'integer',
            $builderEnabled ? 'min:1' : 'min:0',
            'max:1000',
        ];
    }

    protected function requestedBuilderEnabled(Request $request, bool $default): bool
    {
        if (! $request->has('builder_enabled')) {
            return $default;
        }

        return $request->boolean('builder_enabled');
    }
}
