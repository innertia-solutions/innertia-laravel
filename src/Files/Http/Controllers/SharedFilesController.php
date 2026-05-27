<?php

namespace Innertia\Files\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Http\Resources\FileResource;
use Innertia\Files\Models\File;

class SharedFilesController extends Controller
{
    public function index(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        /** @var Authenticatable $user */
        $user      = $request->user();
        $userId    = (string) $user->getAuthIdentifier();
        $userClass = get_class($user);
        $fileClass = File::class;
        $dirClass  = Directory::class;

        $query = File::query()
            // Exclude files the user created themselves ("my files", not "shared with me")
            ->where('created_by', '!=', $userId)
            ->where(function ($q) use ($userId, $userClass, $fileClass, $dirClass) {
                // 1. Direct grant on the file
                $q->whereExists(function ($sub) use ($userId, $userClass, $fileClass) {
                    $sub->from('entity_permissions')
                        ->where('entity_type', $fileClass)
                        ->whereColumn('entity_id', 'files.id')
                        ->where('grantable_type', $userClass)
                        ->where('grantable_id', $userId);
                });

                // 2. Grant on an ancestor directory (via materialized path)
                $q->orWhere(function ($q2) use ($userId, $userClass, $dirClass) {
                    $q2->whereNotNull('files.directory_id')
                       ->whereExists(function ($sub) use ($userId, $userClass, $dirClass) {
                           $sub->from('entity_permissions as ep')
                               ->join('directories as ancestor', 'ancestor.id', '=', 'ep.entity_id')
                               ->join('directories as fd', 'fd.id', '=', 'files.directory_id')
                               ->where('ep.entity_type', $dirClass)
                               ->where('ep.grantable_type', $userClass)
                               ->where('ep.grantable_id', $userId)
                               ->whereRaw("fd.path LIKE (ancestor.path || '%')");
                       });
                });
            });

        if ($search = $request->query('search')) {
            $query->where('original_name', 'like', '%' . $search . '%');
        }

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        return FileResource::collection($query->orderBy('files.created_at', 'desc')->paginate($perPage));
    }
}
