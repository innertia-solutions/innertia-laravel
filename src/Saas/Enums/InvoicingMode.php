<?php

namespace Innertia\Saas\Enums;

enum InvoicingMode: string
{
    case Consolidated = 'consolidated';
    case PerSpace     = 'per_space';
}
