<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SwitchTeamController extends Controller
{
    /**
     * Switch the authenticated user's current team.
     */
    public function __invoke(Request $request, Team $team): RedirectResponse
    {
        abort_unless($request->user()->belongsToTeam($team), 404);

        $request->user()->switchTeam($team);

        return redirect()->route('dashboard');
    }
}
