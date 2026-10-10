<?php

namespace Pinova\Exceptions;

use Exception;

/** An expected eligibility denial, distinct from a storage or callback failure. */
final class AuthenticationPolicyException extends Exception {}
