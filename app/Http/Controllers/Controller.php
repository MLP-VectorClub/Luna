<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * @OA\OpenApi(
 *   @OA\Info(
 *     title="MLP Vector Club API",
 *     version="0.1",
 *     description="A work-in-progress API that will eventually allow programmatic access to all features of the [MLPVector.Club](https://mlpvector.club/) website.",
 *     @OA\License(name="MIT"),
 *     @OA\Contact(name="David Joseph Guzsik", url="https://seinopsys.hu", email="seinopsys@gmail.com"),
 *   ),
 *   @OA\Server(url="/api/v0", description="Unstable API"),
 *   @OA\Tag(name="authentication", description="Endpoints related to getting a user logged in or out, as well as checking logged in status"),
 *   @OA\Tag(name="color guide", description="Endpoints related to the color guide section of the site"),
 *   @OA\Tag(name="appearances", description="Working with entries in the color guide"),
 *   @OA\Tag(name="server info", description="For diagnostic or informational data")
 * )
 */
class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;
}
