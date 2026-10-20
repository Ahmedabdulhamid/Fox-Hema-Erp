<?php

namespace App\Modules\Inventory\Data;

/** Server-created context, never request input. Names must be deployment-allowlisted. */
final readonly class SystemPostingContext
{
    public function __construct(public string $name) {}
}
