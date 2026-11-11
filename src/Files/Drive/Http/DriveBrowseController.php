<?php

namespace Innertia\Files\Drive\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Files\Drive\DriveItem;
use Innertia\Files\Drive\DriveSourceResolver;
use Innertia\Files\Drive\Exceptions\DriveException;
use Innertia\Files\Drive\Exceptions\DriveItemNotFoundException;

/** Navegación de Drive para los selectores del front (Files.DriveBrowser). */
class DriveBrowseController extends Controller
{
    public const ID_RULE = 'regex:/^[A-Za-z0-9_-]{1,200}$/';

    /** GET drive/browse?parent=&drive_id=&folders_only= */
    public function browse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'parent'       => ['nullable', 'string', self::ID_RULE],
            'drive_id'     => ['nullable', 'string', self::ID_RULE],
            'folders_only' => ['nullable', 'boolean'],
        ]);

        return $this->guard(function () use ($data, $request) {
            $source = DriveSourceResolver::resolve();
            $parent = $data['parent'] ?? ($data['drive_id'] ?? 'root');
            $items = $source->listChildren($parent, $data['drive_id'] ?? null, $request->boolean('folders_only'));

            return [
                'items'         => array_map(fn (DriveItem $i) => $i->toArray(), $items),
                'shared_drives' => empty($data['parent']) && empty($data['drive_id']) ? $source->sharedDrives() : null,
            ];
        });
    }

    /** GET drive/items/{id} → ítem + ruta */
    public function item(string $id): JsonResponse
    {
        return $this->guard(function () use ($id) {
            $source = DriveSourceResolver::resolve();

            return ['item' => $source->metadata($id)->toArray(), 'path' => $source->path($id)];
        });
    }

    /** POST drive/folders {name, parent} */
    public function createFolder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'   => ['required', 'string', 'max:200'],
            'parent' => ['nullable', 'string', self::ID_RULE],
        ]);

        return $this->guard(
            fn () => ['item' => DriveSourceResolver::resolve()->createFolder(trim($data['name']), $data['parent'] ?? 'root')->toArray()],
            201,
        );
    }

    private function guard(\Closure $run, int $status = 200): JsonResponse
    {
        try {
            return response()->json($run(), $status);
        } catch (DriveItemNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (DriveException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
