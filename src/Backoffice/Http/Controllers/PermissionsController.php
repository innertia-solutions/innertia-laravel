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
     * Returns permissions grouped by app then by category.
     * Use ?flat=1 to get a flat list of permission names instead.
     *
     * Default response:
     * [
     *   { "app": "backoffice", "app_label": "Backoffice", "groups": [
     *       { "category": "user", "category_alias": "Usuarios", "permissions": [
     *           { "name": "users.view", "description": "Ver lista de usuarios" }
     *       ]}
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

        $apps = array_map(function ($app) {
            return [
                'app'       => $app['app'],
                'app_label' => $app['app_label'],
                'groups'    => array_map(function ($group) {
                    return [
                        'category'       => $group['category'],
                        'category_alias' => $group['category_alias'],
                        'permissions'    => array_map(
                            fn ($name, $description) => compact('name', 'description'),
                            array_keys($group['permissions']),
                            array_values($group['permissions']),
                        ),
                    ];
                }, $app['groups']),
            ];
        }, Permissions::allByApp());

        return response()->json(array_values($apps));
    }
}
