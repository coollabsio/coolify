<?php

namespace App\Livewire\Concerns;

use App\Models\Tag;
use Illuminate\Validation\ValidationException;

/**
 * "New tag" modal of the tags list and the tags canvas.
 */
trait CreatesTags
{
    public string $newTagName = '';

    public function createTag()
    {
        try {
            $this->authorize('create', Tag::class);
            $this->validate(
                ['newTagName' => ['required', 'string', 'min:2', 'max:255', 'regex:/^\S+$/']],
                ['newTagName.regex' => 'A tag name cannot contain spaces.'],
            );
            $name = strtolower(trim(strip_tags($this->newTagName)));
            if (mb_strlen($name) < 2) {
                throw ValidationException::withMessages(['newTagName' => 'The tag name must be at least 2 characters after sanitization.']);
            }
            if (Tag::ownedByCurrentTeam()->where('name', $name)->exists()) {
                throw ValidationException::withMessages(['newTagName' => 'A tag with this name already exists.']);
            }
            Tag::create(['name' => $name, 'team_id' => currentTeam()->id]);

            return $this->redirectRoute('tags.show', ['tagName' => $name], navigate: true);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }
}
