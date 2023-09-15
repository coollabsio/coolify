<?php

namespace App\Http\Livewire\PrivateKey;

use App\Models\PrivateKey;
use Livewire\Component;

class Change extends Component
{
    public PrivateKey $private_key;
    public $public_key;
    protected $rules = [
        'private_key.name' => 'required|string',
        'private_key.description' => 'nullable|string',
        'private_key.private_key' => 'required|string',
        'private_key.is_git_related' => 'nullable|boolean'
    ];
    protected $validationAttributes = [
        'private_key.name' => 'name',
        'private_key.description' => 'description',
        'private_key.private_key' => 'private key'
    ];

    public function mount()
    {
        try {
            $this->public_key = $this->private_key->publicKey();
        }catch(\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function delete()
    {
        try {
            if ($this->private_key->isEmpty()) {
                $this->private_key->delete();
                currentTeam()->privateKeys = PrivateKey::where('team_id', currentTeam()->id)->get();
                return redirect()->route('security.private-key.index');
            }
            $this->emit('error', 'This private key is in use and cannot be deleted. Please delete all servers, applications, and GitHub/GitLab apps that use this private key before deleting it.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function changePrivateKey()
    {
        try {
            $this->private_key->private_key = formatPrivateKey($this->private_key->private_key);
            $this->private_key->save();
            refresh_server_connection($this->private_key);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
