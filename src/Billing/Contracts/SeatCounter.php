<?php

namespace Innertia\Billing\Contracts;

/** Counts the billable users of a space at the close (pomely: active staff of the space). */
interface SeatCounter
{
    public function count(string $tenantId): int;
}
