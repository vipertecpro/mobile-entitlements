<?php

namespace Vipertecpro\MobileEntitlements\Exceptions;

use RuntimeException;

/**
 * A signature, certificate chain, token or claim did not verify. Maps to HTTP 401.
 */
class VerificationException extends RuntimeException {}
