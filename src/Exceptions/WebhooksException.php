<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Exceptions;

use RuntimeException;

/**
 * The base of every exception this package throws, so a host can catch the
 * package as a whole at a boundary and let specific ones through elsewhere.
 */
abstract class WebhooksException extends RuntimeException {}
