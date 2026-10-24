<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeamContext
{
    /**
     * Make sure the user has a current team they belong to, and that any project
     * or task in the route belongs to it. Visiting a resource from another of the
     * user's teams switches to that team; resources from other teams are hidden.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($routeTeam = $this->teamFromRoute($request)) {
            abort_unless($user->belongsToTeam($routeTeam), 404);

            if ($user->current_team_id !== $routeTeam->id) {
                $user->switchTeam($routeTeam);
            }

            return $next($request);
        }

        if ($user->currentTeam && $user->belongsToTeam($user->currentTeam)) {
            return $next($request);
        }

        $firstTeam = $user->teams()->first();

        if (! $firstTeam) {
            return redirect()->route('teams.create');
        }

        $user->switchTeam($firstTeam);

        return $next($request);
    }

    /**
     * Resolve the team that owns the resource being requested, if any.
     */
    private function teamFromRoute(Request $request): ?Team
    {
        $route = $request->route();

        return match (true) {
            $route?->parameter('task') instanceof Task => $route->parameter('task')->project->team,
            $route?->parameter('project') instanceof Project => $route->parameter('project')->team,
            $route?->parameter('team') instanceof Team => $route->parameter('team'),
            default => null,
        };
    }
}
