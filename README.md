# innertia-solutions/laravel-kit

Shared Laravel utilities for Innertia Solutions projects.

## Installation

```bash
composer require innertia-solutions/laravel-kit
```

The package auto-discovers via Laravel's package discovery. Migrations are loaded automatically.

## Features

### DataTable

Flexible server-side datatable with filtering, sorting, and pagination.

```php
use Innertia\Facades\DataTable;

$result = DataTable::create('users')
    ->query(User::query())
    ->columns(['id', 'name', 'email', 'created_at'])
    ->searchable(['name', 'email'])
    ->make();
```

### Activity Logger

```php
use Innertia\Facades\ActivityLogger;

ActivityLogger::logUserAction('login');
ActivityLogger::logEntityAction('updated', 'invoice', $invoice->id, 'Changed amount');
ActivityLogger::logSecurityAction('password_changed');
```

### Entity History

```php
use Innertia\Facades\EntityHistory;

// Automatically record changes (use the Auditable trait)
EntityHistory::recordUpdated($model);

// Query history
$history = EntityHistory::getEntityHistory('invoice', $invoice->id);
```

### Traits

| Trait | Description |
|-------|-------------|
| `HasHistory` | Hook into model events to auto-record entity history |
| `Auditable` | Track created_by / updated_by user IDs |
| `HasNanoId` | Use Nano IDs instead of auto-increment primary keys |
| `UseEnumWithValues` | Helper methods for PHP 8.1+ backed enums |

```php
use Innertia\Traits\HasHistory;
use Innertia\Traits\HasNanoId;

class Invoice extends Model
{
    use HasNanoId, HasHistory;
}
```

### Helpers

```php
traceId(); // Returns the current request trace ID (or '' if not set)
```

## Releasing

Use the **Release** GitHub Actions workflow (workflow_dispatch). Select `patch`, `minor`, or `major` bump. The workflow creates and pushes the git tag — Packagist auto-updates via webhook.

## License

MIT
