<?php

namespace Sunnysideup\Bookings\Exceptions;

use Exception;

/**
 * Base class for booking payment exceptions
 */
abstract class BookingPaymentException extends Exception
{
    /**
     * Get user-friendly error message (safe to display to users)
     */
    abstract public function getUserMessage(): string;
    
    /**
     * Get admin error message (for logging, may contain sensitive info)
     */
    public function getAdminMessage(): string
    {
        return $this->getMessage();
    }
}

/**
 * Payment validation errors
 */
class PaymentValidationException extends BookingPaymentException
{
    public function getUserMessage(): string
    {
        return 'Payment validation failed. Please check your booking details and try again.';
    }
}

/**
 * Payment processing errors
 */
class PaymentProcessingException extends BookingPaymentException
{
    public function getUserMessage(): string
    {
        return 'Payment processing failed. Please try again or contact support.';
    }
}

/**
 * Payment gateway communication errors
 */
class PaymentGatewayException extends BookingPaymentException
{
    public function getUserMessage(): string
    {
        return 'Payment service temporarily unavailable. Please try again in a few minutes.';
    }
}