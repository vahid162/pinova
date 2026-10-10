<?php

namespace Pinova\Exceptions;

use Exception;

/** The code was checked, but the account operation could not complete. */
final class OTPCompletionException extends Exception {
	public function __construct() {
		parent::__construct( __( 'تکمیل درخواست ممکن نشد. کمی بعد دوباره تلاش کنید؛ اگر مشکل ادامه داشت، کد پیگیری را به پشتیبانی بدهید.', 'pinova' ) );
	}
}
