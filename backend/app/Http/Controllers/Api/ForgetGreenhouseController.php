<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Visitors\ForgetGreenhouse;
use App\Http\Controllers\Controller;
use App\Http\Visitors\VisitorCookie;
use App\Models\Visitor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ForgetGreenhouseController extends Controller
{
    public function __invoke(Request $request, ForgetGreenhouse $forget, VisitorCookie $cookie): Response
    {
        $visitor = $request->user();

        if ($visitor instanceof Visitor) {
            $forget->handle($visitor);
        }

        return response()->noContent()->withCookie($cookie->expire());
    }
}
