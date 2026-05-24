<?php

namespace Innertia\Platform\Organizations;

use Closure;

/**
 * Holds the active Organization for the current request/job/command.
 *
 * Two distinct concepts:
 *
 *   current() — int|null. The ONE org used for WRITES. HasOrganization injects
 *               this into `creating` when present.
 *   scope()   — array<int>. The SET of org ids accessible for READS. Default
 *               is [current()]. In consolidated view it can contain many.
 *
 * Registered as a singleton in InnertiaServiceProvider when
 * config('innertia.organizations.enabled') === true.
 */
class OrganizationContext
{
    private ?int $current = null;

    /** @var array<int> */
    private array $scope = [];

    public function current(): ?int
    {
        return $this->current;
    }

    /**
     * @return array<int>
     */
    public function scope(): array
    {
        return $this->scope;
    }

    /**
     * Set the active organization. Also resets scope to [id] (single-org view).
     */
    public function set(int $id): void
    {
        $this->current = $id;
        $this->scope   = [$id];
    }

    /**
     * Override scope without touching current. Used by consolidated view.
     *
     * All ids MUST be non-negative integers. Use this at request boundaries
     * (middleware, controllers) after the values have been validated upstream.
     *
     * @param array<int> $ids
     * @throws \InvalidArgumentException when any element is not a non-negative int
     */
    public function setScope(array $ids): void
    {
        foreach ($ids as $id) {
            if (! is_int($id) || $id < 0) {
                throw new \InvalidArgumentException(
                    'OrganizationContext::setScope() expects an array of non-negative integers; got '
                    . (is_object($id) ? get_class($id) : gettype($id) . '(' . var_export($id, true) . ')')
                );
            }
        }

        $this->scope = array_values(array_unique($ids));
    }

    public function clear(): void
    {
        $this->current = null;
        $this->scope   = [];
    }

    public function inConsolidatedView(): bool
    {
        if ($this->current === null) {
            return count($this->scope) > 1;
        }
        return $this->scope !== [$this->current];
    }

    /**
     * Run $callback with the given organization as current.
     * Original current+scope are restored even if $callback throws.
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function withOrganization(int $id, Closure $callback)
    {
        $prevCurrent = $this->current;
        $prevScope   = $this->scope;
        $this->set($id);
        try {
            return $callback();
        } finally {
            $this->current = $prevCurrent;
            $this->scope   = $prevScope;
        }
    }
}
