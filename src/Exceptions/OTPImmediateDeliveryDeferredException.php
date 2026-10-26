<?php

namespace Pinova\Exceptions;

/** Internal pre-provider admission failure; never a public delivery result. */
final class OTPImmediateDeliveryDeferredException extends \RuntimeException {}
