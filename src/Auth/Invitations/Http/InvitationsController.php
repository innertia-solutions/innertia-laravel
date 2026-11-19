<?php

namespace Innertia\Auth\Invitations\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Innertia\Auth\Invitations\InvitationBroker;
use Innertia\Auth\Invitations\UseCases\ResendInvitation;
use Innertia\Facades\Innertia;
use Innertia\Notifications\Recipients;

/**
 *   GET  {prefix}/{id}/invitation → {invited_at, invitation_expires_at, invitation_expired} | {invited_at: null}
 *   POST {prefix}/{id}/invitation → resend (429 after 3/h; the previous link stops working)
 *
 * Tenant isolation: with tenancy, only users with a context in the active tenant (else 404).
 * Who may call it (permission) is the product's middleware.
 */
class InvitationsController extends Controller
{
    public function show(string $id): JsonResponse
    {
        $user = $this->findUser($id);

        return response()->json(InvitationBroker::status($user) ?? [
            'invited_at' => null, 'invitation_expires_at' => null, 'invitation_expired' => false,
        ]);
    }

    public function resend(string $id): JsonResponse
    {
        $user = $this->findUser($id);
        $result = (new ResendInvitation((string) $user->getKey()))->execute();
        unset($result['url']);

        return response()->json(['sent' => true] + $result);
    }

    protected function findUser(string $id): object
    {
        $model = Recipients::userModel();
        $query = $model::query();

        if (Innertia::tenancyEnabled() && Innertia::tenant() !== null) {
            $tenantId = (string) Innertia::tenant()->getKey();
            $key = 'CAST(' . $query->getModel()->getQualifiedKeyName() . ' AS TEXT)';

            $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('user_contexts')
                ->whereRaw("user_contexts.user_id = {$key}")
                ->where('user_contexts.tenant_id', $tenantId));
        }

        return $query->findOrFail($id);
    }
}
