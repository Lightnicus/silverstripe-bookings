<?php

namespace Sunnysideup\Bookings\Extensions;

use SilverStripe\ORM\DataExtension;
use SilverStripe\Core\Config\Config;
use SilverStripe\Security\Security;
use Sunnysideup\Bookings\Model\TourBookingSettings;
use Sunnysideup\Bookings\Model\PaymentConstants;

/**
 * Class \Sunnysideup\Bookings\Extensions\BookingPaymentExtension
 *
 * @property Booking|BookingPaymentExtension $owner
 * @property string $PaymentStatus
 * @property string $PaymentGateway
 * @property string $PaymentReference
 * @property string $PaymentDate
 * @property string $PaymentIntentId
 * @property int $LastWebhookTimestamp
 * @property string $LastWebhookEventId
 * @method DataList|Payment[] Payments()
 */
class BookingPaymentExtension extends DataExtension
{
    private static $db = [
        'PaymentStatus' => "Enum('Pending,Paid,Failed,Refunded,Cancelled','Pending')",
        'PaymentGateway' => 'Varchar(50)',
        'PaymentReference' => 'Varchar(255)',
        'PaymentDate' => 'Datetime',
        'PaymentIntentId' => 'Varchar(255)', // For Stripe Payment Intent tracking
        'LastWebhookTimestamp' => 'Int',           // Unix timestamp of last processed webhook
        'LastWebhookEventId' => 'Varchar(255)',    // Stripe event ID to prevent duplicates
    ];
    
    private static $has_many = [
        'Payments' => 'SilverStripe\Omnipay\Model\Payment',
    ];
    
    public function requiresPayment(): bool
    {
        // If user is eligible for offline payment, no payment required
        if ($this->isOfflinePaymentUser()) {
            return false;
        }
        
        $newTotal = $this->owner->getTotalPriceFromTicketTypes();
        
        // For booking updates, check if there's actually an amount due
        if ($this->owner->ID && $this->owner->exists()) {
            $totalAmountPaid = 0;
            $payments = $this->owner->Payments();
            foreach ($payments as $payment) {
                if ($payment->Status === 'Captured' || $payment->Status === 'Authorized') {
                    $totalAmountPaid += $payment->Money->getAmount();
                }
            }
            return ($newTotal - $totalAmountPaid) > 0;
        }
        
        // For new bookings, require payment if there's a total
        return $newTotal > 0;
    }
    
    public function getPaymentAmount(): float
    {
        if (!$this->requiresPayment()) {
            return 0;
        }
        
        $newTotal = $this->owner->getTotalPriceFromTicketTypes();
        
        // For booking updates, calculate the difference between new total and amount already paid
        if ($this->owner->ID && $this->owner->exists()) {
            $totalAmountPaid = 0;
            $payments = $this->owner->Payments();
            foreach ($payments as $payment) {
                if ($payment->Status === 'Captured' || $payment->Status === 'Authorized') {
                    $totalAmountPaid += $payment->Money->getAmount();
                }
            }
            return max(0, $newTotal - $totalAmountPaid);
        }
        
        // For new bookings, return the full amount
        return $newTotal;
    }
    
    public function isPaymentComplete(): bool
    {
        return $this->owner->PaymentStatus === 'Paid';
    }
    
    public function canProceedWithoutPayment(): bool
    {
        return !$this->requiresPayment() || $this->isPaymentComplete();
    }
    
    /**
     * Check if current user is eligible for offline payment
     * 
     * @return bool
     */
    public function isOfflinePaymentUser(): bool
    {
        $member = Security::getCurrentUser();
        if (!$member) {
            return false;
        }
        
        $offlineGroupCode = Config::inst()->get(self::class, 'offline_payment_user_group');
        if (!$offlineGroupCode) {
            return false;
        }
        
        return $member->inGroup($offlineGroupCode);
    }
    
    public function updatePaymentStatus(string $status, array $paymentData = []): void
    {
        $this->owner->PaymentStatus = $status;
        if (isset($paymentData['gateway'])) {
            $this->owner->PaymentGateway = $paymentData['gateway'];
        }
        if (isset($paymentData['reference'])) {
            $this->owner->PaymentReference = $paymentData['reference'];
        }
        if (isset($paymentData['payment_intent_id'])) {
            $this->owner->PaymentIntentId = $paymentData['payment_intent_id'];
        }
        if ($status === 'Paid') {
            $this->owner->PaymentDate = date('Y-m-d H:i:s');
        }
        $this->owner->write();
    }
    
    public function getPaymentStatusLabel(): string
    {
        // For offline payments, show "Offline" instead of "Payment Complete"
        if ($this->owner->PaymentStatus === 'Paid' && $this->owner->PaymentGateway === 'Offline') {
            return 'Offline';
        }
        
        $labels = [
            'Pending' => 'Awaiting Payment',
            'Paid' => 'Payment Complete',
            'Failed' => 'Payment Failed',
            'Refunded' => 'Payment Refunded',
            'Cancelled' => 'Payment Cancelled',
        ];
        
        return $labels[$this->owner->PaymentStatus] ?? $this->owner->PaymentStatus;
    }
    
    
    public function getPaymentByIntentId(string $paymentIntentId)
    {
        return $this->owner->Payments()
            ->filter('TransactionReference', $paymentIntentId)
            ->first();
    }
    
    /**
     * Validate that the payment amount matches the current booking total
     * 
     * @param float $paymentAmount The amount being charged
     * @return bool True if valid, false if mismatch detected
     */
    public function validatePaymentAmount(float $paymentAmount): bool
    {
        $expectedAmount = $this->getPaymentAmount();
        $tolerance = 0.01; // Allow for floating point precision issues
        
        return abs($expectedAmount - $paymentAmount) <= $tolerance;
    }

    /**
     * Get validation error message for amount mismatch
     * 
     * @param float $attemptedAmount
     * @return string
     */
    public function getAmountValidationError(float $attemptedAmount): string
    {
        $expectedAmount = $this->getPaymentAmount();
        return sprintf(
            'Payment amount mismatch. Expected: $%.2f, Attempted: $%.2f. Please refresh and try again.',
            $expectedAmount,
            $attemptedAmount
        );
    }
    
    /**
     * Check if webhook event should be processed (not a replay)
     * 
     * @param string $eventId Stripe event ID
     * @param int $eventTimestamp Unix timestamp from Stripe
     * @return bool True if should process, false if replay/duplicate
     */
    public function shouldProcessWebhookEvent(string $eventId, int $eventTimestamp): bool
    {
        // Check for duplicate event ID
        if ($this->owner->LastWebhookEventId === $eventId) {
            return false; // Already processed this exact event
        }
        
        // Check timestamp is within acceptable range (not too old, not from future)
        $now = time();
        $maxAge = 300; // 5 minutes tolerance
        $futureBuffer = 60; // 1 minute future tolerance
        
        if ($eventTimestamp < ($now - $maxAge) || $eventTimestamp > ($now + $futureBuffer)) {
            return false; // Event too old or from future
        }
        
        return true;
    }

    /**
     * Mark webhook event as processed
     */
    public function markWebhookProcessed(string $eventId, int $eventTimestamp): void
    {
        $this->owner->LastWebhookEventId = $eventId;
        $this->owner->LastWebhookTimestamp = $eventTimestamp;
        $this->owner->write();
    }
    
    /**
     * Mark payment as successful with consistent data structure
     * 
     * @param string|null $transactionReference Payment gateway reference
     * @param string|null $paymentIntentId Stripe payment intent ID
     * @param array $additionalData Any additional payment data
     */
    public function markPaymentSuccessful(?string $transactionReference, ?string $paymentIntentId = null, array $additionalData = []): void
    {
        $paymentData = array_merge([
            'gateway' => PaymentConstants::GATEWAY_STRIPE,
            'reference' => $transactionReference,
            'payment_intent_id' => $paymentIntentId,
        ], $additionalData);
        
        $this->updatePaymentStatus(PaymentConstants::STATUS_PAID, $paymentData);
    }

    /**
     * Mark payment as failed with consistent data structure
     * 
     * @param string|null $transactionReference Payment gateway reference
     * @param string|null $errorMessage Optional error message
     * @param array $additionalData Any additional payment data
     */
    public function markPaymentFailed(?string $transactionReference, ?string $errorMessage = null, array $additionalData = []): void
    {
        $paymentData = array_merge([
            'gateway' => PaymentConstants::GATEWAY_STRIPE,
            'reference' => $transactionReference,
            'error_message' => $errorMessage,
        ], $additionalData);
        
        $this->updatePaymentStatus(PaymentConstants::STATUS_FAILED, $paymentData);
    }

    /**
     * Mark payment as refunded with consistent data structure
     * 
     * @param string $transactionReference Payment gateway reference
     * @param float $refundAmount Amount refunded
     * @param array $additionalData Any additional payment data
     */
    public function markPaymentRefunded(string $transactionReference, float $refundAmount = 0, array $additionalData = []): void
    {
        $paymentData = array_merge([
            'gateway' => PaymentConstants::GATEWAY_STRIPE,
            'reference' => $transactionReference,
            'refund_amount' => $refundAmount,
        ], $additionalData);
        
        $this->updatePaymentStatus(PaymentConstants::STATUS_REFUNDED, $paymentData);
    }
    
    /**
     * Get the configured payment currency
     * 
     * @return string Always returns 'NZD' for this implementation
     */
    public function getPaymentCurrency(): string
    {
        $currency = Config::inst()->get('BookingPaymentSettings', 'currency');
        
        // Validation: Only allow NZD for this implementation
        if ($currency !== 'NZD') {
            user_error('Only NZD currency is supported in this booking system', E_USER_WARNING);
            return 'NZD';
        }
        
        return $currency;
    }
} 