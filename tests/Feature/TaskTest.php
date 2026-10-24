<?php

use App\Enums\TaskStatus;
use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssigned;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->team = $this->project->team;
});

test('members can create tasks and the assignee is notified', function () {
    Notification::fake();
    $member = teamUser($this->team, TeamRole::Member);
    $assignee = teamUser($this->team, TeamRole::Member);

    Livewire::actingAs($member)
        ->test('pages::projects.show', ['project' => $this->project])
        ->set('taskForm.title', 'Write copy')
        ->set('taskForm.assignee_id', (string) $assignee->id)
        ->set('taskForm.priority', 'high')
        ->set('taskForm.due_on', '2026-11-03')
        ->call('createTask')
        ->assertHasNoErrors();

    $task = $this->project->tasks()->sole();
    expect($task)
        ->title->toBe('Write copy')
        ->assignee_id->toBe($assignee->id)
        ->creator_id->toBe($member->id);

    Notification::assertSentTo($assignee, TaskAssigned::class);
});

test('tasks cannot be assigned to people outside the team', function () {
    $outsider = User::factory()->create();

    Livewire::actingAs(teamUser($this->team, TeamRole::Member))
        ->test('pages::projects.show', ['project' => $this->project])
        ->set('taskForm.title', 'Sneaky')
        ->set('taskForm.assignee_id', (string) $outsider->id)
        ->call('createTask')
        ->assertHasErrors('taskForm.assignee_id');
});

test('viewers have read-only access to tasks', function () {
    $viewer = teamUser($this->team, TeamRole::Viewer);
    $task = Task::factory()->for($this->project)->create(['title' => 'Original']);

    $this->actingAs($viewer)->get(route('tasks.show', $task))->assertOk()->assertSee('Original');

    Livewire::actingAs($viewer)
        ->test('pages::projects.show', ['project' => $this->project])
        ->set('taskForm.title', 'New')
        ->call('createTask')
        ->assertForbidden();

    Livewire::actingAs($viewer)
        ->test('pages::projects.show', ['project' => $this->project])
        ->call('moveTask', $task->id, 0, 'done')
        ->assertForbidden();

    $taskPage = fn () => Livewire::actingAs($viewer)->test('pages::tasks.show', ['task' => $task]);
    $taskPage()->set('form.title', 'Changed')->call('save')->assertForbidden();
    $taskPage()->set('commentBody', 'Hi')->call('postComment')->assertForbidden();
    $taskPage()->set('checklistBody', 'Step')->call('addChecklistItem')->assertForbidden();

    expect($task->fresh())->title->toBe('Original')->status->toBe(TaskStatus::Todo)
        ->and($task->comments()->count())->toBe(0);
});

test('dragging a task to another column moves it and logs the activity', function () {
    $member = teamUser($this->team, TeamRole::Member);
    $first = Task::factory()->for($this->project)->create(['status' => 'done', 'position' => 0]);
    $second = Task::factory()->for($this->project)->create(['status' => 'done', 'position' => 1]);
    $task = Task::factory()->for($this->project)->create(['title' => 'Task X']);

    Livewire::actingAs($member)
        ->test('pages::projects.show', ['project' => $this->project])
        ->call('moveTask', $task->id, 1, 'done');

    expect($task->fresh())
        ->status->toBe(TaskStatus::Done)
        ->completed_at->not->toBeNull()
        ->and($this->project->tasks()->where('status', 'done')->orderBy('position')->pluck('id')->all())
        ->toBe([$first->id, $task->id, $second->id]);

    expect($this->project->activities()->sole()->description)->toBe('moved “Task X” to Done');
});

test('tasks from another project cannot be moved through this board', function () {
    $foreignTask = Task::factory()->create();

    Livewire::actingAs(teamUser($this->team, TeamRole::Admin))
        ->test('pages::projects.show', ['project' => $this->project])
        ->call('moveTask', $foreignTask->id, 0, 'done')
        ->assertNotFound();
});

test('tasks in archived projects cannot be changed', function () {
    $project = Project::factory()->for($this->team)->archived()->create();
    $task = Task::factory()->for($project)->create();

    Livewire::actingAs(teamUser($this->team, TeamRole::Admin))
        ->test('pages::projects.show', ['project' => $project])
        ->call('moveTask', $task->id, 0, 'done')
        ->assertForbidden();
});

test('members can delete their own tasks but not other peoples', function () {
    $member = teamUser($this->team, TeamRole::Member);
    $own = Task::factory()->for($this->project)->create(['creator_id' => $member->id]);
    $others = Task::factory()->for($this->project)->create();

    Livewire::actingAs($member)->test('pages::tasks.show', ['task' => $others])->call('deleteTask')->assertForbidden();
    Livewire::actingAs($member)->test('pages::tasks.show', ['task' => $own])->call('deleteTask')->assertRedirect(route('projects.show', $this->project));

    expect(Task::find($own->id))->toBeNull()
        ->and(Task::find($others->id))->not->toBeNull();
});

test('reassigning a task notifies the new assignee and logs it', function () {
    Notification::fake();
    $member = teamUser($this->team, TeamRole::Member);
    $assignee = teamUser($this->team, TeamRole::Member);
    $task = Task::factory()->for($this->project)->create();

    Livewire::actingAs($member)
        ->test('pages::tasks.show', ['task' => $task])
        ->set('form.assignee_id', (string) $assignee->id)
        ->call('save')
        ->assertHasNoErrors();

    Notification::assertSentTo($assignee, TaskAssigned::class);
    expect($task->subjectActivities()->sole()->description)->toContain("to {$assignee->name}");
});

test('checklists and labels can be managed on a task', function () {
    $member = teamUser($this->team, TeamRole::Member);
    $task = Task::factory()->for($this->project)->create();

    $page = Livewire::actingAs($member)->test('pages::tasks.show', ['task' => $task])
        ->set('checklistBody', 'Draft outline')
        ->call('addChecklistItem')
        ->set('labelName', 'Design')
        ->set('labelColor', 'violet')
        ->call('createLabel')
        ->assertHasNoErrors();

    $item = $task->checklistItems()->sole();
    $page->call('toggleChecklistItem', $item->id);

    expect($item->fresh()->is_completed)->toBeTrue()
        ->and($task->labels()->pluck('name')->all())->toBe(['Design'])
        ->and($this->team->labels()->count())->toBe(1);
});
