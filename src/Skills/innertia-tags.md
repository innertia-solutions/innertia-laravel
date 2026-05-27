---
name: innertia-tags
description: Tag any Eloquent model with the Innertia Tags feature. Trait, scopes, HTTP endpoints, install command, extension patterns.
---

# Innertia Tags

Sistema de tags genérico, polimórfico, tenant-scoped y opt-in. Cualquier modelo Eloquent del producto puede usar tags agregando un trait.

## Cuándo usar

- Usuarios finales necesitan etiquetar entidades de negocio (cotizaciones, clientes, documentos, activos, etc.).
- Filtros transversales con AND/OR sobre etiquetas libres.
- Taxonomía compartida a nivel tenant (no por organización).

NO uses tags si:
- Necesitas jerarquía/árbol → usá directorios (Files feature) o una entidad de dominio propia (ej. `ProjectCategory`).
- Necesitas metadata estructurada por categoría → modelo propio.

## Habilitar

1. `.env`: `INNERTIA_TAGS_ENABLED=true`
2. `php artisan innertia:tags:install` → genera migration.
3. `php artisan migrate`.
4. `routes/api.private.php`:
   ```php
   Route::middleware(['auth:api', 'tenant.require'])->group(function () {
       \Innertia\Tags\Routes::register();
   });
   ```
5. Configurar `taggable_types` en `config/innertia.php`:
   ```php
   'tags' => [
       'taggable_types' => [
           'quotes'  => \App\Domains\Quotes\Models\Quote::class,
           'clients' => \App\Domains\Clients\Models\Client::class,
       ],
   ],
   ```

## Aplicar a un modelo

```php
use Innertia\Tags\Traits\HasTags;

class Quote extends Model
{
    use HasTags;
}
```

## API del trait

```php
$quote->tag('vip', 'urgente');             // attach (crea si no existen)
$quote->tag(['vip', 'urgente']);           // array form
$quote->untag('vip');                      // detach
$quote->retag(['nuevo', 'lista']);         // reemplazar set completo
$quote->clearTags();                       // remover todos

$quote->tags;                              // Collection<Tag>
$quote->hasTag('vip');                     // bool
$quote->hasAnyTag(['vip', 'urgente']);     // bool
$quote->hasAllTags(['vip', 'pagado']);     // bool

// Scopes
Quote::withTag('vip')->get();
Quote::withAnyTag(['vip', 'urgente'])->get();
Quote::withAllTags(['vip', 'pagado'])->get();
Quote::withoutTag('archivado')->get();
Quote::withoutAnyTag(['spam', 'duplicado'])->get();
```

## HTTP endpoints

| Verbo | Path | Acción |
|---|---|---|
| GET | `/tags` | Listar (`?search=`, `?ids=`, `?per_page=`) |
| GET | `/tags/popular` | Top por uso (`?limit=`, `?taggable_type=`) |
| POST | `/tags` | Crear |
| GET | `/tags/{id}` | Mostrar |
| PATCH | `/tags/{id}` | Actualizar |
| DELETE | `/tags/{id}` | Borrar |
| GET | `/taggables/{type}/{id}/tags` | Tags de una entidad |
| POST | `/taggables/{type}/{id}/tags` | Attach `{ "tags": [...] }` |
| PUT | `/taggables/{type}/{id}/tags` | Sync `{ "tags": [...] }` |
| DELETE | `/taggables/{type}/{id}/tags/{tagId}` | Detach individual |

`{type}` viene de `config('innertia.tags.taggable_types')`.

## Autorización

Por default, attach/detach requiere `$user->can('update', $entity)` (Laravel policy).

Override con callback en config:

```php
'authorize_attach' => fn ($user, $entity) => $entity->owner_id === $user->id,
```

## Extender controller (template method)

```php
class TagsController extends \Innertia\Tags\Http\Controllers\TagsController
{
    protected function extraStoreRules(): array { return ['icon' => 'required|string']; }
    protected function extraFields(Request $r, ?Tag $tag = null): array {
        return ['icon' => $r->input('icon')];
    }
}

\Innertia\Tags\Routes::register('tags', App\Http\TagsController::class);
```

## Extender modelo

```php
// config/innertia.php
'tags' => [
    'model' => App\Models\Tag::class,
],

// app/Models/Tag.php
class Tag extends \Innertia\Tags\Models\Tag
{
    protected $fillable = [...parent::$fillable, 'icon'];
}
```

## Slugify custom

```php
'slug_generator' => fn ($name) => Str::slug($name, '_'),
```

## Performance

Tablas indexadas:
- `tags (tenant_id, slug)` unique
- `taggables (taggable_type, taggable_id)` para listar tags de una entidad
- `taggables (tag_id)` para listar entidades por tag

`withAllTags` usa `whereHas` con `count` — escala bien hasta ~100k registros. Si pasa eso, considerá denormalizar a una columna `tags_cache JSONB`.

## Eventos emitidos

Tags emite 6 eventos típados que cualquier producto puede escuchar:

| Evento | Cuándo dispara | Payload |
|---|---|---|
| `TagEvent::Created` | `CreateTag::execute()` | tag_id, name, slug, color |
| `TagEvent::Updated` | `UpdateTag::execute()` | tag_id, changes (old/new diff) |
| `TagEvent::Deleted` | `DeleteTag::execute()` | tag_id, slug |
| `TagEvent::Attached` | `AttachTags::execute()` | entity_type, entity_id, slugs |
| `TagEvent::Detached` | `DetachTags::execute()` | entity_type, entity_id, slugs |
| `TagEvent::Synced` | `SyncTags::execute()` | entity_type, entity_id, added, removed |

Escuchar:

```php
use Innertia\Facades\Innertia;
use Innertia\Tags\Events\TagEvent;

Innertia::events()->listen(TagEvent::Created, function ($event) {
    Log::info("Tag created: {$event->tag->slug}");
});
```

## Skills relacionados

- `innertia-extending` — patrón template method para customización
- `innertia-config` — referencia de `config('innertia.tags')`
