<?php

namespace Pinova\Exceptions;

use Exception;

/** A verified signature whose deadline has passed; never exposes token contents. */
class ExpiredTokenException extends Exception {
}
