<?php

use App\Enums\ProjectRole;
use App\Enums\ProjectVisibility;
use App\Enums\TeamRole;

test('effective project roles resolve from team role, project role and visibility', function (?TeamRole $teamRole, ?ProjectRole $projectRole, ProjectVisibility $visibility, ?ProjectRole $expected) {
    expect(ProjectRole::resolve($teamRole, $projectRole, $visibility))->toBe($expected);
})->with([
    'team owner manages any project' => [TeamRole::Owner, null, ProjectVisibility::Private, ProjectRole::Manager],
    'team admin manages any project' => [TeamRole::Admin, null, ProjectVisibility::Private, ProjectRole::Manager],
    'team admin cannot be downgraded' => [TeamRole::Admin, ProjectRole::Viewer, ProjectVisibility::Team, ProjectRole::Manager],
    'project role upgrades a team viewer' => [TeamRole::Viewer, ProjectRole::Contributor, ProjectVisibility::Team, ProjectRole::Contributor],
    'project role downgrades a team member' => [TeamRole::Member, ProjectRole::Viewer, ProjectVisibility::Team, ProjectRole::Viewer],
    'project role grants access to private projects' => [TeamRole::Viewer, ProjectRole::Manager, ProjectVisibility::Private, ProjectRole::Manager],
    'team member contributes by default' => [TeamRole::Member, null, ProjectVisibility::Team, ProjectRole::Contributor],
    'team viewer views by default' => [TeamRole::Viewer, null, ProjectVisibility::Team, ProjectRole::Viewer],
    'team member is hidden from private projects' => [TeamRole::Member, null, ProjectVisibility::Private, null],
    'team viewer is hidden from private projects' => [TeamRole::Viewer, null, ProjectVisibility::Private, null],
    'non-members have no role' => [null, ProjectRole::Manager, ProjectVisibility::Team, null],
]);
