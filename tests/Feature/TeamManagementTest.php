<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationSent;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('admins can invite people by email with a role', function () {
    Notification::fake();
    $team = Team::factory()->create();
    $admin = teamUser($team, TeamRole::Admin);

    Livewire::actingAs($admin)
        ->test('pages::teams.settings', ['team' => $team])
        ->set('inviteEmail', 'newcomer@example.com')
        ->set('inviteRole', 'viewer')
        ->call('invite')
        ->assertHasNoErrors();

    $invitation = $team->invitations()->sole();
    expect($invitation->email)->toBe('newcomer@example.com')
        ->and($invitation->role)->toBe(TeamRole::Viewer);

    Notification::assertSentTo(new AnonymousNotifiable, TeamInvitationSent::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'newcomer@example.com');
});

test('invitations cannot grant the owner role', function () {
    $team = Team::factory()->create();

    Livewire::actingAs(teamUser($team, TeamRole::Admin))
        ->test('pages::teams.settings', ['team' => $team])
        ->set('inviteEmail', 'newcomer@example.com')
        ->set('inviteRole', 'owner')
        ->call('invite')
        ->assertHasErrors('inviteRole');
});

test('members and viewers cannot invite or remove people', function (TeamRole $role) {
    $team = Team::factory()->create();
    $target = teamUser($team, TeamRole::Member);

    Livewire::actingAs(teamUser($team, $role))
        ->test('pages::teams.settings', ['team' => $team])
        ->set('inviteEmail', 'newcomer@example.com')
        ->call('invite')
        ->assertForbidden();

    Livewire::actingAs(teamUser($team, $role))
        ->test('pages::teams.settings', ['team' => $team])
        ->call('removeMember', $target->id)
        ->assertForbidden();

    expect($team->invitations()->count())->toBe(0)
        ->and($target->belongsToTeam($team))->toBeTrue();
})->with([TeamRole::Member, TeamRole::Viewer]);

test('the invited user can accept and joins with the invited role', function () {
    $user = User::factory()->create(['email' => 'pat@example.com']);
    $invitation = TeamInvitation::factory()->create(['email' => 'pat@example.com', 'role' => TeamRole::Admin]);

    Livewire::actingAs($user)
        ->test('pages::invitations.show', ['invitation' => $invitation])
        ->call('accept')
        ->assertRedirect(route('dashboard'));

    expect($user->roleOn($invitation->team))->toBe(TeamRole::Admin)
        ->and($user->fresh()->current_team_id)->toBe($invitation->team_id)
        ->and(TeamInvitation::count())->toBe(0);
});

test('the invited user can decline', function () {
    $user = User::factory()->create(['email' => 'pat@example.com']);
    $invitation = TeamInvitation::factory()->create(['email' => 'pat@example.com']);

    Livewire::actingAs($user)
        ->test('pages::invitations.show', ['invitation' => $invitation])
        ->call('decline');

    expect($user->belongsToTeam($invitation->team))->toBeFalse()
        ->and(TeamInvitation::count())->toBe(0);
});

test('someone else cannot use an invitation', function () {
    $invitation = TeamInvitation::factory()->create(['email' => 'pat@example.com']);

    $this->actingAs(User::factory()->create(['email' => 'intruder@example.com']))
        ->get(route('invitations.show', $invitation))
        ->assertForbidden();
});

test('admins can change member roles but not the owner role or their own', function () {
    $team = Team::factory()->create();
    $owner = teamUser($team, TeamRole::Owner);
    $admin = teamUser($team, TeamRole::Admin);
    $member = teamUser($team, TeamRole::Member);

    $settings = fn () => Livewire::actingAs($admin)->test('pages::teams.settings', ['team' => $team]);

    $settings()->call('changeRole', $member->id, 'viewer')->assertOk();
    expect($member->roleOn($team))->toBe(TeamRole::Viewer);

    $settings()->call('changeRole', $owner->id, 'viewer')->assertForbidden();
    $settings()->call('changeRole', $member->id, 'owner')->assertStatus(422);
    $settings()->call('changeRole', $admin->id, 'member')->assertForbidden();

    expect($owner->roleOn($team))->toBe(TeamRole::Owner)
        ->and($admin->roleOn($team))->toBe(TeamRole::Admin);
});

test('the owner can transfer ownership and becomes an admin', function () {
    $team = Team::factory()->create();
    $owner = teamUser($team, TeamRole::Owner);
    $member = teamUser($team, TeamRole::Member);

    Livewire::actingAs($owner)
        ->test('pages::teams.settings', ['team' => $team])
        ->set('newOwnerId', (string) $member->id)
        ->call('transferOwnership')
        ->assertHasNoErrors();

    expect($member->roleOn($team))->toBe(TeamRole::Owner)
        ->and($owner->roleOn($team))->toBe(TeamRole::Admin);
});

test('only the owner can transfer ownership or delete the team', function () {
    $team = Team::factory()->create();
    teamUser($team, TeamRole::Owner);
    $admin = teamUser($team, TeamRole::Admin);
    $member = teamUser($team, TeamRole::Member);

    $settings = fn () => Livewire::actingAs($admin)->test('pages::teams.settings', ['team' => $team]);

    $settings()->set('newOwnerId', (string) $member->id)->call('transferOwnership')->assertForbidden();
    $settings()->set('deleteConfirmation', $team->name)->call('deleteTeam')->assertForbidden();

    expect(Team::find($team->id))->not->toBeNull();
});

test('the owner can delete the team after typing its name', function () {
    $team = Team::factory()->create(['name' => 'Acme']);
    $owner = teamUser($team, TeamRole::Owner);

    $settings = Livewire::actingAs($owner)->test('pages::teams.settings', ['team' => $team]);

    $settings->set('deleteConfirmation', 'acme')->call('deleteTeam')->assertHasErrors('deleteConfirmation');
    expect(Team::find($team->id))->not->toBeNull();

    $settings->set('deleteConfirmation', 'Acme')->call('deleteTeam')->assertRedirect(route('dashboard'));
    expect(Team::find($team->id))->toBeNull();
});

test('the owner cannot leave without transferring ownership', function () {
    $team = Team::factory()->create();

    Livewire::actingAs(teamUser($team, TeamRole::Owner))
        ->test('pages::teams.settings', ['team' => $team])
        ->call('leaveTeam')
        ->assertForbidden();
});

test('viewers cannot rename the team', function () {
    $team = Team::factory()->create(['name' => 'Acme']);

    Livewire::actingAs(teamUser($team, TeamRole::Viewer))
        ->test('pages::teams.settings', ['team' => $team])
        ->set('name', 'Hijacked')
        ->call('updateTeam')
        ->assertForbidden();

    expect($team->fresh()->name)->toBe('Acme');
});
