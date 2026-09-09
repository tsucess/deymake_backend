<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * Raised when a payment gateway call fails or the gateway is not configured.
 */
class PaymentGatewayException extends RuntimeException {}
