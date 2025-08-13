<?php

namespace Sunnysideup\Bookings\Model;

/**
 * Constants for payment processing
 */
class PaymentConstants
{
    const GATEWAY_STRIPE = 'Stripe';
    const GATEWAY_MANUAL = 'Manual';
    
    const STATUS_PENDING = 'Pending';
    const STATUS_PAID = 'Paid';
    const STATUS_FAILED = 'Failed';
    const STATUS_REFUNDED = 'Refunded';
    const STATUS_CANCELLED = 'Cancelled';
}