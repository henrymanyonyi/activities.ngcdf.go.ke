<?php

namespace App\Exceptions;

use RuntimeException;

/** A business rule stopped the action. The message is safe to show to the user. */
class ActivityWorkflowException extends RuntimeException {}
