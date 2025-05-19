<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use App\Utils\Core;
use App\Utils\GitHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use OpenApi\Annotations as OA;

class AboutController extends Controller
{
    /**
     * @OA\Schema(
     *   schema="ConnectionInfo",
     *   type="object",
     *   description="An object containing information about the connection made to the server",
     *   required={
     *     "ip",
     *     "proxiedIps",
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="ip",
     *     type="string",
     *     format="ip",
     *     description="The IP address the server believes this request originated from",
     *     example="10.0.0.2",
     *     nullable=true,
     *   ),
     *   @OA\Property(
     *     property="proxiedIps",
     *     type="string",
     *     description="The value of the X-Forwarded-For HTTP header as received by the server",
     *     example="192.168.0.2, 10.0.0.2, 172.16.0.2",
     *     nullable=true,
     *   ),
     *   @OA\Property(
     *     property="userAgent",
     *     type="string",
     *     description="The value of the User-Agent HTTP header as received by the server",
     *     example="Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:47.0) Gecko/20100101 Firefox/47.0",
     *     nullable=true,
     *   ),
     *   @OA\Property(
     *     property="deviceIdentifier",
     *     type="string",
     *     description="Short string representing the current browser and OS used to make the request (based on user agent)",
     *     example="Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:47.0) Gecko/20100101 Firefox/47.0",
     *     nullable=true,
     *   ),
     * )
     * @OA\Get(
     *   path="/about/connection",
     *   description="Get diagnostic data related to the API connection and app server",
     *   tags={"server info"},
     *   security={},
     *   @OA\Response(
     *     response="200",
     *     description="OK",
     *     @OA\JsonContent(
     *       allOf={
     *         @OA\Schema(ref="#/components/schemas/ConnectionInfo"),
     *         @OA\Schema(ref="#/components/schemas/CommitData")
     *       }
     *     )
     *   ),
     *   @OA\Response(
     *     response="503",
     *     description="The application server is currently unavailable, more information may be in the request body",
     *     @OA\JsonContent(
     *       allOf={
     *         @OA\Schema(ref="#/components/schemas/ErrorResponse")
     *       }
     *     )
     *   )
     * )
     */
    public function serverInfo()
    {
        $commit_data = GitHelper::getCommitData();
        return response()->camelJson([
            'commit_id' => $commit_data['commit_id'] ?? null,
            'commit_time' => Core::carbonToIso($commit_data['commit_time'] ?? null),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'proxied_ips' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'device_identifier' => Core::getDeviceIdentifier(),
        ]);
    }

    /**
     * @OA\Get(
     *   path="/about/members",
     *   description="Get a list of club members and staff (users above the 'user' role)",
     *   tags={"users"},
     *   security={},
     *   @OA\Response(
     *     response="200",
     *     description="Query successful",
     *     @OA\JsonContent(
     *       type="array",
     *       @OA\Items(ref="#/components/schemas/PublicUser")
     *     )
     *   ),
     *   @OA\Response(
     *     response="401",
     *     description="Unathorized",
     *   )
     * )
     *
     * @param  Request  $request
     * @param  User  $user
     * @return \Illuminate\Http\JsonResponse
     */
    public function members(Request $request, User $user)
    {
        $users = User::where('role', '!=', Role::User)->orderBy('name')->get();
        return response()->camelJson($users->map(fn (User $u) => $u->publicResponse()));
    }

    /**
     * An undocumented endpoint for development use that just loads "forever"
     *
     * nginx will likely terminate the connection sooner though
     *
     * @param  Request  $request
     */
    public function sleep(Request $request)
    {
        if (App::isProduction()) {
            abort(404);
        }

        sleep(60 * 60);

        return redirect($request->path());
    }
}
