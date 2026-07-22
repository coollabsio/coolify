<?php

it('shows an empty state when there are no projects', function () {
    $this->view('livewire.project.index', [
        'projects' => collect(),
    ])
        ->assertSee('No projects found.')
        ->assertSee('onboarding');
});

it('does not show the empty state when projects exist', function () {
    $project = new class
    {
        public string $name = 'Test Project';

        public string $description = 'A project description';

        public string $uuid = 'test-project-uuid';

        public $environments;

        public function __construct()
        {
            $this->environments = collect();
        }

        public function navigateTo(): string
        {
            return '#';
        }
    };

    $this->view('livewire.project.index', [
        'projects' => collect([$project]),
    ])
        ->assertSee('Test Project')
        ->assertDontSee('No projects found.');
});
