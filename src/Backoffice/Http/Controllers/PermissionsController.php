<?php

namespace Innertia\Backoffice\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Facades\Permissions;

class PermissionsController extends Controller
{
    /**
     * GET /backoffice/permissions
     *
     * Returns all named permissions defined in config, grouped by category.
     * Use ?flat=1 to get a flat list of permission names instead.
     *
     * Grouped response:
     * [
     *   { "category": "users", "category_alias": "Usuarios", "permissions": [
     *       { "name": "users.view", "description": "Ver lista de usuarios" }
     *   ]}
     * ]
     *
     * Flat response (?flat=1):
     * ["users.view", "users.manage", "clients.view"]
     */
    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('flat')) {
            return response()->json(Permissions::keys());
        }

        $groups = array_map(function ($group) {
            return [
                'category'       => $group['category'],
                'category_alias' => $group['category_alias'],
                'permissions'    => array_map(
                    fn ($name, $description) => compact('name', 'description'),
                    array_keys($group['permissions']),
                    array_values($group['permissions']),
                ),
            ];
        }, Permissions::all());

        return response()->json(array_values($groups));
    }
}
