<?php

use App\Enums\ProjectRole;
use App\Enums\TeamRole;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Notifications\MentionedInComment;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Create a user on the project's team with the given team role and, optionally, a project role.
 */
function projectUser(Project $project, TeamRole $teamRole, ?ProjectRole $projectRole = null): User
{
    $user = teamUser($project->team, $teamRole);

    if ($projectRole) {
        $project->members()->attach($user->id, ['role' => $projectRole->value]);
    }

    return $user;
}

dataset('project access', [
    'project manager' => [TeamRole::Member, ProjectRole::Manager],
    'project contributor' => [TeamRole::Viewer, ProjectRole::Contributor],
    'project viewer' => [TeamRole::Member, ProjectRole::Viewer],
    'team admin' => [TeamRole::Admin, null],
]);

test('each project role can do exactly what the permission table allows', function (TeamRole $teamRole, ?ProjectRole $projectRole, string $action, array $allowedFor) {
    $project = Project::factory()->private()->create();
    $user = projectUser($project, $teamRole, $projectRole);
    $othersTask = Task::factory()->for($project)->create();
    $ownTask = Task::factory()->for($project)->create(['creator_id' => $user->id]);

    $allowed = match ($action) {
        'view project' => $user->can('view', $project),
        'comment' => $user->can('comment', $othersTask),
        'create tasks' => $user->can('create', [Task::class, $project]),
        'edit tasks' => $user->can('update', $othersTask),
        'delete own tasks' => $user->can('delete', $ownTask),
        'delete others tasks' => $user->can('delete', $othersTask),
        'edit project settings' => $user->can('update', $project),
        'manage project members' => $user->can('manageMembers', $project),
        'archive project' => $user->can('archive', $project),
        'delete project' => $user->can('delete', $project),
    };

    $key = $projectRole?->value ?? 'team admin';

    expect($allowed)->toBe(in_array($key, $allowedFor, true));
})->with('project access')->with([
    'view project' => ['view project', ['manager', 'contributor', 'viewer', 'team admin']],
    'comment' => ['comment', ['manager', 'contributor', 'viewer', 'team admin']],
    'create tasks' => ['create tasks', ['manager', 'contributor', 'team admin']],
    'edit tasks' => ['edit tasks', ['manager', 'contributor', 'team admin']],
    'delete own tasks' => ['delete own tasks', ['manager', 'contributor', 'team admin']],
    'delete others tasks' => ['delete others tasks', ['manager', 'team admin']],
    'edit project settings' => ['edit project settings', ['manager', 'team admin']],
    'manage project members' => ['manage project members', ['manager', 'team admin']],
    'archive project' => ['archive project', ['manager', 'team admin']],
    'delete project' => ['delete project', ['team admin']],
]);

test('private projects are hidden from team members without a project role', function () {
    $project = Project::factory()->private()->create(['name' => 'Payroll Revamp']);
    $member = projectUser($project, TeamRole::Member);
    Task::factory()->for($project)->create(['title' => 'Salary bands', 'assignee_id' => $member->id]);
    $task = $project->tasks()->first();

    $this->actingAs($member)->get(route('projects.show', $project))->assertNotFound();
    $this->actingAs($member)->get(route('tasks.show', $task))->assertNotFound();
    $this->actingAs($member)->get(route('projects.index'))->assertOk()->assertDontSee('Payroll Revamp');
    $this->actingAs($member)->get(route('dashboard'))->assertOk()->assertDontSee('Salary bands')->assertDontSee('Payroll Revamp');
});

test('private projects are visible to project members and team admins', function (TeamRole $teamRole, ?ProjectRole $projectRole) {
    $project = Project::factory()->private()->create(['name' => 'Payroll Revamp']);
    $user = projectUser($project, $teamRole, $projectRole);

    $this->actingAs($user)->get(route('projects.show', $project))->assertOk();
    $this->actingAs($user)->get(route('projects.index'))->assertSee('Payroll Revamp');
})->with('project access');

test('a project role shows on the project header', function () {
    $project = Project::factory()->create();
    $user = projectUser($project, TeamRole::Viewer, ProjectRole::Contributor);

    $this->actingAs($user)->get(route('projects.show', $project))->assertSee('You: Contributor');
});

test('a project contributor role lets a team viewer create tasks on that project only', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->for($project->team)->create();
    $contractor = projectUser($project, TeamRole::Viewer, ProjectRole::Contributor);

    Livewire::actingAs($contractor)
        ->test('pages::projects.show', ['project' => $project])
        ->set('taskForm.title', 'Design homepage')
        ->call('createTask')
        ->assertHasNoErrors();

    Livewire::actingAs($contractor)
        ->test('pages::projects.show', ['project' => $otherProject])
        ->set('taskForm.title', 'Not allowed')
        ->call('createTask')
        ->assertForbidden();

    expect($project->tasks()->count())->toBe(1)
        ->and($otherProject->tasks()->count())->toBe(0);
});

test('project managers can add, change and remove project members, with activity logged', function () {
    $project = Project::factory()->create(['name' => 'Website Redesign']);
    $lead = projectUser($project, TeamRole::Member, ProjectRole::Manager);
    $ana = projectUser($project, TeamRole::Viewer);
    $ana->update(['name' => 'Ana']);

    $page = fn () => Livewire::actingAs($lead)->test('pages::projects.show', ['project' => $project]);

    $page()->set('newMemberId', (string) $ana->id)->set('newMemberRole', 'viewer')->call('addMember')->assertHasNoErrors();
    expect($ana->effectiveProjectRole($project->fresh()))->toBe(ProjectRole::Viewer);

    $page()->call('changeMemberRole', $ana->id, 'contributor');
    expect($ana->effectiveProjectRole($project->fresh()))->toBe(ProjectRole::Contributor)
        ->and($project->activities()->pluck('description'))->toContain('made Ana a Contributor on “Website Redesign”');

    $page()->call('removeMember', $ana->id);
    expect($project->members()->whereKey($ana->id)->exists())->toBeFalse();
});

test('only team members without full access can be added to a project', function () {
    $project = Project::factory()->create();
    $manager = projectUser($project, TeamRole::Member, ProjectRole::Manager);
    $outsider = User::factory()->create();
    $teamAdmin = projectUser($project, TeamRole::Admin);

    foreach ([$outsider, $teamAdmin] as $candidate) {
        Livewire::actingAs($manager)
            ->test('pages::projects.show', ['project' => $project])
            ->set('newMemberId', (string) $candidate->id)
            ->call('addMember')
            ->assertHasErrors('newMemberId');
    }

    expect($project->members()->count())->toBe(1);
});

test('contributors and viewers cannot manage project members or visibility', function (ProjectRole $role) {
    $project = Project::factory()->create();
    $user = projectUser($project, TeamRole::Member, $role);
    $candidate = projectUser($project, TeamRole::Viewer);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('newMemberId', (string) $candidate->id)
        ->call('addMember')
        ->assertForbidden();

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->call('setVisibility', 'private')
        ->assertForbidden();

    expect($project->fresh()->isPrivate())->toBeFalse();
})->with([ProjectRole::Contributor, ProjectRole::Viewer]);

test('the last manager of a private project cannot be removed or demoted except by a team admin', function () {
    $project = Project::factory()->private()->create();
    $lead = projectUser($project, TeamRole::Member, ProjectRole::Manager);
    $admin = projectUser($project, TeamRole::Admin);

    Livewire::actingAs($lead)
        ->test('pages::projects.show', ['project' => $project])
        ->call('changeMemberRole', $lead->id, 'viewer')
        ->assertHasErrors('members');

    Livewire::actingAs($lead)
        ->test('pages::projects.show', ['project' => $project])
        ->call('removeMember', $lead->id)
        ->assertHasErrors('members');

    expect($lead->effectiveProjectRole($project->fresh()))->toBe(ProjectRole::Manager);

    Livewire::actingAs($admin)
        ->test('pages::projects.show', ['project' => $project])
        ->call('removeMember', $lead->id)
        ->assertHasNoErrors();

    expect($lead->effectiveProjectRole($project->fresh()))->toBeNull();
});

test('a manager can step down when another manager remains', function () {
    $project = Project::factory()->private()->create();
    $lead = projectUser($project, TeamRole::Member, ProjectRole::Manager);
    projectUser($project, TeamRole::Member, ProjectRole::Manager);

    Livewire::actingAs($lead)
        ->test('pages::projects.show', ['project' => $project])
        ->call('changeMemberRole', $lead->id, 'contributor')
        ->assertHasNoErrors();

    expect($lead->effectiveProjectRole($project->fresh()))->toBe(ProjectRole::Contributor);
});

test('a project manager can make the project private and the change is logged', function () {
    $project = Project::factory()->create(['name' => 'Payroll Revamp']);
    $lead = projectUser($project, TeamRole::Member, ProjectRole::Manager);
    $bystander = projectUser($project, TeamRole::Member);

    Livewire::actingAs($lead)
        ->test('pages::projects.show', ['project' => $project])
        ->call('setVisibility', 'private');

    expect($project->fresh()->isPrivate())->toBeTrue()
        ->and($bystander->can('view', $project->fresh()))->toBeFalse()
        ->and($project->activities()->pluck('description'))->toContain('made “Payroll Revamp” private');
});

test('removing someone from the team removes their project roles', function () {
    $team = Team::factory()->create();
    $owner = teamUser($team, TeamRole::Owner);
    $project = Project::factory()->for($team)->private()->create();
    $contractor = projectUser($project, TeamRole::Viewer, ProjectRole::Contributor);

    Livewire::actingAs($owner)
        ->test('pages::teams.settings', ['team' => $team])
        ->call('removeMember', $contractor->id);

    expect($project->members()->count())->toBe(0);
});

test('people without access to a private project cannot be assigned or mentioned', function () {
    Notification::fake();
    $project = Project::factory()->private()->create();
    $lead = projectUser($project, TeamRole::Member, ProjectRole::Manager);
    $outsideTeammate = projectUser($project, TeamRole::Member);
    $outsideTeammate->update(['name' => 'Nosy Ned']);
    $task = Task::factory()->for($project)->create();

    Livewire::actingAs($lead)
        ->test('pages::projects.show', ['project' => $project])
        ->set('taskForm.title', 'Secret')
        ->set('taskForm.assignee_id', (string) $outsideTeammate->id)
        ->call('createTask')
        ->assertHasErrors('taskForm.assignee_id');

    Livewire::actingAs($lead)
        ->test('pages::tasks.show', ['task' => $task])
        ->set('commentBody', 'Hey @Nosy Ned')
        ->call('postComment');

    Notification::assertNotSentTo($outsideTeammate, MentionedInComment::class);
});

test('project managers can delete any comment on their project', function () {
    $project = Project::factory()->create();
    $lead = projectUser($project, TeamRole::Member, ProjectRole::Manager);
    $comment = Comment::factory()->for(Task::factory()->for($project))->create();

    Livewire::actingAs($lead)
        ->test('pages::tasks.show', ['task' => $comment->task])
        ->call('deleteComment', $comment->id);

    expect(Comment::find($comment->id))->toBeNull();
});
