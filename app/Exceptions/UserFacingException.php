<?php

namespace App\Exceptions;

use Throwable;

/**
 * An exception whose message is written for users, so the UI may show it as is.
 * Every other exception is replaced by a fixed sentence (see FailureMessage) and
 * only ends up in the log.
 */
interface UserFacingException extends Throwable {}
