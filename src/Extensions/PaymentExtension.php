<?php

namespace Sunnysideup\Bookings\Extensions;

use SilverStripe\ORM\DataExtension;
use Sunnysideup\Bookings\Model\Booking;

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
}
