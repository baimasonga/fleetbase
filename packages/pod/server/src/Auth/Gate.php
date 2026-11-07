<?php

namespace Fleetbase\Pod\Auth;

/**
 * Answers whether the signed-in person holds a PoD permission
 * ("pod <action> <resource>").
 */
interface Gate
{
    public function allows(string $permission): bool;
}
