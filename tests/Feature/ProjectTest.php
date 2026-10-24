<?php

use App\Enums\TaskStatus;
use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use Livewire\Livewire;

test('admins can create projects', function (TeamRole $role) {
    $team = Team::factory()->create();

    Livewire::actingAs(teamUser($team, $role))
        ->test('pages::projects.index')
        ->set('form.name', 'Website Redesign')
        ->set('form.color', 'violet')
        ->set('form.starts_on', '2026-11-01')
        ->set('form.ends_on', '2026-12-01')
        ->call('createProject')
        ->assertHasNoErrors();

    $project = $team->projects()->sole();
    expect($project->name)->toBe('Website Redesign')
        ->and($project->activities()->count())->toBe(1);
})->with([TeamRole::Owner, TeamRole::Admin]);

test('members and viewers cannot create projects', function (TeamRole $role) {
    $team = Team::factory()->create();

    Livewire::actingAs(teamUser($team, $role))
        ->test('pages::projects.index')
        ->set('form.name', 'Website Redesign')
        ->call('createProject')
        ->assertForbidden();

    expect($team->projects()->count())->toBe(0);
})->with([TeamRole::Member, TeamRole::Viewer]);

test('project end date cannot be before its start date', function () {
    $team = Team::factory()->create();

    Livewire::actingAs(teamUser($team, TeamRole::Admin))
        ->test('pages::projects.index')
        ->set('form.name', 'Backwards')
        ->set('form.starts_on', '2026-12-01')
        ->set('form.ends_on', '2026-11-01')
        ->call('createProject')
        ->assertHasErrors('form.ends_on');
});

test('members cannot edit, archive or delete projects', function () {
    $project = Project::factory()->create(['name' => 'Original']);
    $member = teamUser($project->team, TeamRole::Member);

    $page = fn () => Livewire::actingAs($member)->test('pages::projects.show', ['project' => $project]);

    $page()->set('projectForm.name', 'Changed')->call('updateProject')->assertForbidden();
    $page()->call('toggleArchive')->assertForbidden();
    $page()->call('deleteProject')->assertForbidden();

    expect($project->fresh())->name->toBe('Original')->archived_at->toBeNull();
});

test('admins can archive and delete projects', function () {
    $project = Project::factory()->create();
    $admin = teamUser($project->team, TeamRole::Admin);

    $page = Livewire::actingAs($admin)->test('pages::projects.show', ['project' => $project]);

    $page->call('toggleArchive');
    expect($project->fresh()->isArchived())->toBeTrue();

    $page->call('deleteProject')->assertRedirect(route('projects.index'));
    expect(Project::find($project->id))->toBeNull();
});

test('archived projects are hidden from the active list', function () {
    $team = Team::factory()->create();
    $user = teamUser($team, TeamRole::Viewer);
    Project::factory()->for($team)->create(['name' => 'Live project']);
    Project::factory()->for($team)->archived()->create(['name' => 'Old project']);

    Livewire::actingAs($user)->test('pages::projects.index')
        ->assertSee('Live project')
        ->assertDontSee('Old project')
        ->set('archived', true)
        ->assertSee('Old project')
        ->assertDontSee('Live project');
});

test('progress reflects the share of done tasks', function () {
    $project = Project::factory()->create();
    Task::factory()->for($project)->count(3)->create();
    Task::factory()->for($project)->done()->create();

    expect($project->progress())->toBe(25)
        ->and(Project::withProgress()->find($project->id)->progress())->toBe(25)
        ->and(Project::factory()->create()->progress())->toBe(0);
});

test('the list view filters by status, priority, assignee and due date', function () {
    $project = Project::factory()->create();
    $user = teamUser($project->team, TeamRole::Member);
    Task::factory()->for($project)->create(['title' => 'Mine and urgent', 'assignee_id' => $user->id, 'priority' => 'urgent']);
    Task::factory()->for($project)->overdue()->create(['title' => 'Late one', 'priority' => 'low']);
    Task::factory()->for($project)->done()->create(['title' => 'Finished', 'priority' => 'low']);

    $page = Livewire::actingAs($user)->test('pages::projects.show', ['project' => $project])->set('view', 'list');

    $page->set('assigneeFilter', 'me')->assertSee('Mine and urgent')->assertDontSee('Late one');
    $page->call('clearFilters')->set('dueFilter', 'overdue')->assertSee('Late one')->assertDontSee('Mine and urgent');
    $page->call('clearFilters')->set('statusFilter', TaskStatus::Done->value)->assertSee('Finished')->assertDontSee('Late one');
    $page->call('clearFilters')->set('priorityFilter', 'urgent')->assertSee('Mine and urgent')->assertDontSee('Finished');
});
