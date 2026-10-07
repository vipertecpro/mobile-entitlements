<?php

namespace Vipertecpro\MobileEntitlements\Exceptions;

use RuntimeException;

/**
 * The verified purchase is already linked to a different user. Maps to HTTP 409.
 */
class EntitlementOwnedByAnotherUser extends RuntimeException {}
