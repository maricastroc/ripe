<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Agent\UpdateAgentSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAgentRequest;
use App\Http\Resources\AgentStatusResource;
use App\Models\Visitor;
use App\Orchestration\AgentStatusReader;
use Illuminate\Http\Request;

final class AgentController extends Controller
{
    public function show(Request $request, AgentStatusReader $agent): AgentStatusResource
    {
        $visitor = $request->user();

        return new AgentStatusResource($agent->for($visitor instanceof Visitor ? $visitor : null));
    }

    public function update(UpdateAgentRequest $request, UpdateAgentSettings $settings, AgentStatusReader $agent): AgentStatusResource
    {
        $settings->handle($request->pace(), $request->paused(), $request->model());

        $visitor = $request->user();

        return new AgentStatusResource($agent->for($visitor instanceof Visitor ? $visitor : null));
    }
}
