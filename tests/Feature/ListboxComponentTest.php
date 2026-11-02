<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Component;
use Livewire\Livewire;

function multipleListboxTestComponent(): Component
{
    return new class extends Component
    {
        public array $ids = [];

        public function mount(array $ids = []): void
        {
            $this->ids = $ids;
        }

        public function save(): void
        {
            $this->validate([
                'ids' => 'required|array',
                'ids.*' => 'integer|in:1,2,3',
            ], [
                'ids.required' => 'Pick at least one server.',
                'ids.*.in' => 'Unknown server selected.',
            ]);
        }

        public function render(): string
        {
            return <<<'BLADE'
                <div>
                    <x-forms.listbox id="ids" label="Servers" :multiple="true" placeholder="Pick servers" :options="[
                        ['value' => 'group', 'label' => 'Group', 'header' => true],
                        ['value' => 1, 'label' => 'Alpha'],
                        ['value' => 2, 'label' => 'Beta'],
                        ['value' => 3, 'label' => 'Gamma'],
                    ]" />
                </div>
            BLADE;
        }
    };
}

test('multiple listbox shows the placeholder when nothing is selected', function (mixed $ids) {
    Livewire::test(multipleListboxTestComponent(), ['ids' => $ids])
        ->assertSeeHtml('aria-multiselectable="true"')
        ->assertSeeHtml('x-text="current">Pick servers</span>');
})->with([[[]], [['group']]]);

test('multiple listbox joins up to two selected labels in the trigger', function () {
    // Ids that arrive as strings must match integer option values.
    Livewire::test(multipleListboxTestComponent(), ['ids' => ['2', 1]])
        ->assertSeeHtml('x-text="current">Alpha, Beta</span>');
});

test('multiple listbox shows a count when more than two options are selected', function () {
    Livewire::test(multipleListboxTestComponent(), ['ids' => [1, 2, 3]])
        ->assertSeeHtml('x-text="current">3 selected</span>');
});

test('client-side multiple listbox treats a non-array value as empty', function () {
    $html = Blade::render(<<<'BLADE'
        <x-forms.listbox id="tags" :wire="false" :multiple="true" value="1" placeholder="No tags"
            :options="[['value' => 1, 'label' => 'One']]" />
    BLADE);

    expect($html)
        ->toContain('x-text="current">No tags</span>')
        ->toMatch('/value:\\s*\\[\\]\\s*,/');
});

test('single listbox output has no multiple-selection attributes', function () {
    $html = Blade::render(<<<'BLADE'
        <x-forms.listbox id="mode" :wire="false" value="a" :options="[
            ['value' => 'a', 'label' => 'A'],
            ['value' => 'b', 'label' => 'B'],
        ]" />
    BLADE);

    expect($html)
        ->not->toContain('aria-multiselectable')
        ->not->toContain('get selected()')
        ->toContain('x-text="current"></span>')
        ->toMatch("/value:\\s*'a'\\s*,/");
});

test('searchable listbox renders a search field with its placeholders', function () {
    $html = Blade::render(<<<'BLADE'
        <x-forms.listbox id="server" :wire="false" searchable searchPlaceholder="Search servers"
            searchEmptyText="No matching server" :options="[['value' => 1, 'label' => 'One']]" />
    BLADE);

    expect($html)
        ->toContain('type="search"')
        ->toContain('placeholder="Search servers"')
        ->toContain('No matching server');
});

test('searchable listbox with allowCustom offers the typed value as an option', function () {
    $html = Blade::render(<<<'BLADE'
        <x-forms.listbox id="path" :wire="false" searchable allowCustom :options="[['value' => 'Dockerfile', 'label' => 'Dockerfile']]" />
    BLADE);

    expect($html)
        ->toContain('x-if="customOption"')
        ->toContain('!customOption');
});

test('listbox without allowCustom offers no custom option', function () {
    $html = Blade::render(<<<'BLADE'
        <x-forms.listbox id="path" :wire="false" searchable :options="[['value' => 'Dockerfile', 'label' => 'Dockerfile']]" />
    BLADE);

    expect($html)->not->toContain('x-if="customOption"');
});

test('listbox without searchable renders no search field', function () {
    $html = Blade::render(<<<'BLADE'
        <x-forms.listbox id="server" :wire="false" :options="[['value' => 1, 'label' => 'One']]" />
    BLADE);

    expect($html)->not->toContain('type="search"');
});

test('multiple listbox shows the array validation error', function () {
    Livewire::test(multipleListboxTestComponent())
        ->call('save')
        ->assertHasErrors(['ids' => 'required'])
        ->assertSee('Pick at least one server.');
});

test('multiple listbox shows item validation errors', function () {
    Livewire::test(multipleListboxTestComponent(), ['ids' => [1, 9]])
        ->call('save')
        ->assertHasErrors(['ids.1' => 'in'])
        ->assertSee('Unknown server selected.');
});

test('multiple listbox shows no error when the selection is valid', function () {
    Livewire::test(multipleListboxTestComponent(), ['ids' => [1, 2]])
        ->call('save')
        ->assertHasNoErrors()
        ->assertDontSee('Pick at least one server.')
        ->assertDontSee('Unknown server selected.');
});
