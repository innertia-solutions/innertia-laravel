<?php

namespace Innertia\PublicApi\Docs;

use Illuminate\Routing\Route;
use ReflectionMethod;
use Throwable;

/**
 * Reads the descriptor of a route: route macro `->apiDoc([...])` (stored in the action, survives route:cache)
 * or the #[ApiOperation] attribute on the controller method.
 */
final class RouteDescriptor
{
    public const ACTION_KEY = 'innertia_api';

    /** @var array<string, array|null> */
    private static array $attributeCache = [];

    public static function for(?Route $route): ?array
    {
        if ($route === null) {
            return null;
        }

        $fromMacro = $route->getAction(self::ACTION_KEY);
        if (is_array($fromMacro)) {
            return $fromMacro;
        }

        $uses = $route->getAction('uses');
        if (! is_string($uses) || ! str_contains($uses, '@')) {
            return null;
        }

        if (! array_key_exists($uses, self::$attributeCache)) {
            self::$attributeCache[$uses] = self::fromAttribute($uses);
        }

        return self::$attributeCache[$uses];
    }

    /** Scope required by the route descriptor, if any. */
    public static function scopeFor(?Route $route): ?string
    {
        $scope = self::for($route)['scope'] ?? null;

        return is_string($scope) && $scope !== '' ? $scope : null;
    }

    private static function fromAttribute(string $uses): ?array
    {
        [$class, $method] = explode('@', $uses, 2);

        try {
            $attributes = (new ReflectionMethod($class, $method))->getAttributes(ApiOperation::class);
        } catch (Throwable) {
            return null;
        }

        return $attributes === [] ? null : $attributes[0]->newInstance()->toArray();
    }
}
