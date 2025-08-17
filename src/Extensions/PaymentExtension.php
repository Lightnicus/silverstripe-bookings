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
 * @property string $PaymentIntentId
 * @property int $BookingID
 * @method Booking Booking()
 */
class PaymentExtension extends DataExtension
{
    private static $db = [
        'PaymentIntentId' => 'Varchar(255)', // Store Stripe Payment Intent ID
    ];
    
    private static $has_one = [
        'Booking' => Booking::class,
    ];
    
    /**
     * Update the summary fields to include both charge ID and payment intent ID
     */
    public function updateSummaryFields(&$fields)
    {
        // Add TransactionReference and PaymentIntentId after Gateway
        $newFields = [];
        foreach ($fields as $key => $label) {
            $newFields[$key] = $label;
            if ($key === 'GatewayTitle') {
                $newFields['getTransactionReferenceFormatted'] = 'Charge ID';
                $newFields['getPaymentIntentIdFormatted'] = 'Payment Intent ID';
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
     * Get a formatted payment intent ID for display
     * 
     * @return string
     */
    public function getPaymentIntentIdFormatted()
    {
        $paymentIntentId = $this->owner->PaymentIntentId;
        if (empty($paymentIntentId)) {
            return 'No payment intent ID';
        }
        
        return $paymentIntentId;
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

        // Extract charge ID from Payment Intent response data if available
        $chargeId = null;
        $extractionDetails = [
            'paymentID' => $this->owner->ID,
            'hasOmnipayResponse' => !empty($omnipayResponse),
            'hasGetDataMethod' => false,
            'responseData' => null,
            'chargesStructure' => null,
            'extractedChargeId' => null,
            'extractionSuccess' => false
        ];
        
        if ($omnipayResponse && method_exists($omnipayResponse, 'getData')) {
            $extractionDetails['hasGetDataMethod'] = true;
            $data = $omnipayResponse->getData();
            $extractionDetails['responseData'] = is_array($data) ? array_keys($data) : gettype($data);
            
            // Log the charges structure for debugging
            if (isset($data['charges'])) {
                $extractionDetails['chargesStructure'] = [
                    'charges_exists' => true,
                    'charges_type' => gettype($data['charges']),
                    'has_data_key' => isset($data['charges']['data']),
                    'data_type' => isset($data['charges']['data']) ? gettype($data['charges']['data']) : null,
                    'data_count' => isset($data['charges']['data']) && is_array($data['charges']['data']) ? count($data['charges']['data']) : 0,
                    'first_charge_keys' => isset($data['charges']['data'][0]) && is_array($data['charges']['data'][0]) ? array_keys($data['charges']['data'][0]) : null
                ];
                
                // Check for charges in Payment Intent response
                if (isset($data['charges']['data'][0]['id'])) {
                    $chargeId = $data['charges']['data'][0]['id'];
                    $extractionDetails['extractedChargeId'] = $chargeId;
                    $extractionDetails['extractionSuccess'] = true;
                }
            } else {
                $extractionDetails['chargesStructure'] = [
                    'charges_exists' => false,
                    'has_latest_charge' => isset($data['latest_charge']),
                    'latest_charge_type' => isset($data['latest_charge']) ? gettype($data['latest_charge']) : null,
                    'available_keys' => is_array($data) ? array_keys($data) : 'not_array'
                ];
                
                // Payment Intent API uses 'latest_charge' field instead of 'charges' array
                if (isset($data['latest_charge']) && is_string($data['latest_charge'])) {
                    $chargeId = $data['latest_charge'];
                    $extractionDetails['extractedChargeId'] = $chargeId;
                    $extractionDetails['extractionSuccess'] = true;
                    $extractionDetails['extraction_method'] = 'latest_charge';
                }
            }
        }
        
        // Log the extraction attempt
        PaymentLogger::info('payment.charge_id_extraction', $extractionDetails);

        // Track what we need to update
        $needsUpdate = false;
        $storageDetails = [
            'paymentID' => $this->owner->ID,
            'currentPaymentIntentId' => $this->owner->PaymentIntentId,
            'currentTransactionReference' => $this->owner->TransactionReference,
            'newPaymentIntentRef' => $paymentIntentRef,
            'newChargeId' => $chargeId,
            'willUpdatePaymentIntentId' => false,
            'willUpdateTransactionReference' => false,
            'writeNeeded' => false
        ];
        
        // Store Payment Intent ID if we have it and it's not already set
        if ($paymentIntentRef && empty($this->owner->PaymentIntentId)) {
            $this->owner->PaymentIntentId = $paymentIntentRef;
            $needsUpdate = true;
            $storageDetails['willUpdatePaymentIntentId'] = true;
        }
        
        // Store charge ID if we found it and it's not already set
        if ($chargeId && empty($this->owner->TransactionReference)) {
            $this->owner->TransactionReference = $chargeId;
            $needsUpdate = true;
            $storageDetails['willUpdateTransactionReference'] = true;
        }
        
        $storageDetails['writeNeeded'] = $needsUpdate;
        
        // Log the storage attempt
        PaymentLogger::info('payment.reference_storage', $storageDetails);
        
        // Write once if we updated anything
        if ($needsUpdate) {
            $this->owner->write();
            
            PaymentLogger::info('payment.reference_storage_complete', [
                'paymentID' => $this->owner->ID,
                'finalPaymentIntentId' => $this->owner->PaymentIntentId,
                'finalTransactionReference' => $this->owner->TransactionReference
            ]);
        }

        // Update booking status to successful (use the extracted charge ID if we found it)
        $finalChargeId = $chargeId ?: $transactionRef;
        $booking->markPaymentSuccessful($finalChargeId, $paymentIntentRef);
        
        PaymentLogger::info('payment.captured', [
            'bookingID' => $booking->ID,
            'bookingCode' => $booking->Code,
            'paymentID' => $this->owner->ID,
            'transactionReference' => $finalChargeId,
            'paymentIntentReference' => $paymentIntentRef,
            'chargeExtracted' => !empty($chargeId),
        ]);
    }
}
