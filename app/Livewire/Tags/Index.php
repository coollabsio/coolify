<?php

namespace App\Livewire\Tags;

use App\Models\Tag;
use Livewire\Component;

class Index extends Component
{
    public $tags = [];
    public function mount() {
        $this->tags = Tag::where('team_id', currentTeam()->id)->get()->unique('name')->sortBy('name');
    }
    public function render()
    {
        return view('livewire.tags.index');
    }
}
