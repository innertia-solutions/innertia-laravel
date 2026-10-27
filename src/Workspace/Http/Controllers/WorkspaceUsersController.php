<?php

namespace Innertia\Workspace\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Workspace\WorkspaceException;
use Innertia\Workspace\WorkspaceManager;

class WorkspaceUsersController extends Controller
{
    public function index(Request $request, WorkspaceManager $manager): JsonResponse
    {
        if (! $manager->configured()) {
            return response()->json(['message' => 'Google Workspace no está configurado. Configuralo en /platform/integrations.'], 409);
        }
        $data = $request->validate([
            'search'     => ['sometimes', 'nullable', 'string'],
            'page_token' => ['sometimes', 'nullable', 'string'],
        ]);

        return $this->guard(fn () => response()->json(
            $manager->provider()->listUsers($data['search'] ?? null, $data['page_token'] ?? null)
        ));
    }

    public function store(Request $request, WorkspaceManager $manager): JsonResponse
    {
        if (! $manager->configured()) {
            return response()->json(['message' => 'Google Workspace no está configurado.'], 409);
        }
        $data = $request->validate([
            'email'       => ['required', 'email'],
            'given_name'  => ['required', 'string', 'max:120'],
            'family_name' => ['required', 'string', 'max:120'],
            'password'    => ['required', 'string', 'min:8'],
            'change_next' => ['sometimes', 'boolean'],
        ]);

        return $this->guard(fn () => response()->json(
            $manager->provider()->createUser($data['email'], $data['given_name'], $data['family_name'], $data['password'], $data['change_next'] ?? true),
            201
        ));
    }

    public function password(Request $request, WorkspaceManager $manager, string $userKey): JsonResponse
    {
        $data = $request->validate([
            'password'    => ['required', 'string', 'min:8'],
            'change_next' => ['sometimes', 'boolean'],
        ]);

        return $this->guard(fn () => response()->json(
            $manager->provider()->resetPassword($userKey, $data['password'], $data['change_next'] ?? true)
        ));
    }

    public function suspend(Request $request, WorkspaceManager $manager, string $userKey): JsonResponse
    {
        $data = $request->validate(['suspended' => ['required', 'boolean']]);

        return $this->guard(fn () => response()->json(
            $manager->provider()->setSuspended($userKey, $data['suspended'])
        ));
    }

    public function destroy(WorkspaceManager $manager, string $userKey): JsonResponse
    {
        return $this->guard(function () use ($manager, $userKey) {
            $manager->provider()->deleteUser($userKey);

            return response()->json(null, 204);
        });
    }

    /** Mapea WorkspaceException → 422 con el mensaje de Google. */
    private function guard(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (WorkspaceException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
