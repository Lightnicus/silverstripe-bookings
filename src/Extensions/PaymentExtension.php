<?php

namespace Sunnysideup\Bookings\Extensions;

use SilverStripe\ORM\DataExtension;
use Sunnysideup\Bookings\Model\Booking;
use Sunnysideup\Bookings\Logging\PaymentLogger;
use Sunnysideup\Bookings\Model\PaymentConstants;

/**
 * Extension for SilverStripe\Omnipay\Model\Payment to add relationship back to Booking
 * and enhance the admin display with transaction reference
 *
 * @property Payment|PaymentExtension $owner
 * @property int $BookingID
 * @method Booking Booking()
 */
class PaymentExtension extends DataExtension
{
    private static $has_one = [
        'Booking' => Booking::class,
    ];
    
    /**
     * Update the summary fields to include the transaction reference (charge ID)
     */
    public function updateSummaryFields(&$fields)
    {
        // Add TransactionReference after Gateway
        $newFields = [];
        foreach ($fields as $key => $label) {
            $newFields[$key] = $label;
            if ($key === 'GatewayTitle') {
                $newFields['getTransactionReferenceFormatted'] = 'Charge ID';
            }
        }
        $fields = $newFields;
    }
    
    /**
     * Get a formatted transaction reference for display
     * 
     * @return string
     */
    public function getTransactionReferenceFormatted()
    {
        $reference = $this->owner->TransactionReference;
        if (empty($reference)) {
            return 'No charge ID';
        }
        
        return $reference;
    }

    /**
     * Hook into successful payment capture to update related booking
     */
    public function onCaptured($serviceResponse): void
    {
        // Only handle Stripe Payment Intents
        if ($this->owner->Gateway !== PaymentConstants::GATEWAY_STRIPE_PAYMENT_INTENTS) {
            return;
        }

        // Find the related booking
        $booking = Booking::get()->filter('ID', $this->owner->BookingID)->first();
        if (!$booking) {
            return;
        }

        // Get transaction references
        $transactionRef = $this->owner->TransactionReference;
        $omnipayResponse = $serviceResponse->getOmnipayResponse();
        $paymentIntentRef = $omnipayResponse && method_exists($omnipayResponse, 'getPaymentIntentReference') 
            ? $omnipayResponse->getPaymentIntentReference() 
            : null;

        // Update booking status to successful
        $booking->markPaymentSuccessful($transactionRef, $paymentIntentRef);
        
        PaymentLogger::info('payment.captured', [
            'bookingID' => $booking->ID,
            'bookingCode' => $booking->Code,
            'paymentID' => $this->owner->ID,
            'transactionReference' => $transactionRef,
            'paymentIntentReference' => $paymentIntentRef,
        ]);
    }
}
