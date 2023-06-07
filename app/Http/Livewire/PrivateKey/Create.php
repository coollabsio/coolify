<?php

namespace App\Http\Livewire\PrivateKey;

use App\Models\PrivateKey;
use Livewire\Component;

class Create extends Component
{
    protected string|null $from = null;
    public string $name;
    public string|null $description = null;
    public string $value;
    protected $rules = [
        'name' => 'required|string',
        'value' => 'required|string',
    ];
    protected $validationAttributes = [
        'name' => 'Name',
        'value' => 'Private Key',
    ];
    public function createPrivateKey()
    {
        $this->validate();
        try {
            $this->value = trim($this->value);
            if (!str_ends_with($this->value, "\n")) {
                $this->value .= "\n";
            }
            $private_key = PrivateKey::create([
                'name' => $this->name,
                'description' => $this->description,
                'private_key' => $this->value,
                'team_id' => session('currentTeam')->id
            ]);
            if ($this->from === 'server') {
                return redirect()->route('server.create');
            }
            return redirect()->route('private-key.show', ['private_key_uuid' => $private_key->uuid]);
        } catch (\Exception $e) {
            return general_error_handler($e, $this);
        }
    }
}
