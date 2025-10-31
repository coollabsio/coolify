<?php

namespace App\Livewire\Concerns;

trait SynchronizesModelData
{
    /**
     * Define the mapping between component properties and model keys.
     *
     * @return array<string, string> Array mapping property names to model keys (e.g., ['content' => 'fileStorage.content'])
     */
    abstract protected function getModelBindings(): array;

    /**
     * Synchronize component properties TO the model.
     * Copies values from component properties to the model.
     */
    protected function syncToModel(): void
    {
        foreach ($this->getModelBindings() as $property => $modelKey) {
            data_set($this, $modelKey, $this->{$property});
        }
    }

    /**
     * Synchronize component properties FROM the model.
     * Copies values from the model to component properties.
     */
    protected function syncFromModel(): void
    {
        foreach ($this->getModelBindings() as $property => $modelKey) {
            $this->{$property} = data_get($this, $modelKey);
        }
    }
}
