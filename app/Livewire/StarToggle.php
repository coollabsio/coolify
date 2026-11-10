<?php

namespace App\Livewire;

use App\Models\Star;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Stars or unstars a resource for the logged-in user. Starred resources show on the dashboard.
 */
class StarToggle extends Component
{
    #[Locked]
    public string $starrableType;

    #[Locked]
    public int $starrableId;

    public bool $starred = false;

    public function mount(Model $resource): void
    {
        $this->authorize('view', $resource);

        $this->starrableType = $resource->getMorphClass();
        $this->starrableId = $resource->getKey();
        $this->starred = $this->starQuery()->exists();
    }

    public function toggle(): void
    {
        abort_unless(in_array($this->starrableType, Star::STARRABLE_TYPES, true), 404);
        $this->authorize('view', $this->starrableType::findOrFail($this->starrableId));

        $this->starred = ! $this->starQuery()->exists();

        if (! $this->starred) {
            $this->starQuery()->delete();
        } else {
            auth()->user()->stars()->firstOrCreate([
                'starrable_type' => $this->starrableType,
                'starrable_id' => $this->starrableId,
            ], [
                'position' => (auth()->user()->stars()->max('position') ?? -1) + 1,
            ]);
        }

        $this->dispatch('star-toggled', type: $this->starrableType, id: $this->starrableId, starred: $this->starred);
    }

    /**
     * Keeps the other star button of the same resource on the page (desktop and mobile top bars) in sync.
     */
    #[On('star-toggled')]
    public function syncStar(string $type, int $id, bool $starred): void
    {
        if ($type !== $this->starrableType || $id !== $this->starrableId || $starred === $this->starred) {
            $this->skipRender();

            return;
        }

        $this->starred = $starred;
    }

    private function starQuery(): HasMany
    {
        return auth()->user()->stars()
            ->where('starrable_type', $this->starrableType)
            ->where('starrable_id', $this->starrableId);
    }

    public function render()
    {
        return view('livewire.star-toggle');
    }
}
