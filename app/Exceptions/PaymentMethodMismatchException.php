<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A subscription or charge could not be saved because its payment method
 * does not exist for the chosen card, or belongs to a different card.
 * Nothing is guessed or created automatically. The message is Thai.
 */
class PaymentMethodMismatchException extends RuntimeException {}
