<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A charge could not be recorded or changed because the data moved on
 * (cycle already recorded, subscription no longer active, closed period not
 * confirmed, ...). The message is Thai and safe to show to the user.
 */
class ChargeConflictException extends RuntimeException {}
