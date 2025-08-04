<?php

namespace Sunnysideup\Bookings\Extensions;

use SilverStripe\ORM\DataExtension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use Sunnysideup\Bookings\Model\TourBookingSettings;

/**
 * Class \Sunnysideup\Bookings\Extensions\TourBookingFormPaymentExtension
 *
 * @property TourBookingForm|TourBookingFormPaymentExtension $owner
 */
class TourBookingFormPaymentExtension extends DataExtension
{
    public function updateFields(FieldList $fields)
    {
        if ($this->isPaymentEnabled()) {
            $fields->push($this->getPaymentFields());
        }
    }
    
    public function updateActions(FieldList $actions)
    {
        if ($this->isPaymentEnabled()) {
            // Update submit button text if payment required
            $submitAction = $actions->fieldByName('action_dobooking');
            if ($submitAction) {
                $submitAction->setTitle('Book & Pay Now');
            }
        }
    }
    
    protected function isPaymentEnabled(): bool
    {
        return TourBookingSettings::inst()->EnablePayments;
    }
    
    protected function getPaymentFields(): FieldList
    {
        $fields = FieldList::create();
        
        // Add payment information
        $fields->push(
            LiteralField::create(
                'PaymentInfo',
                '<div class="payment-info">Payment will be processed securely via Stripe</div>'
            )
        );
        
        // Add Stripe Elements container
        $fields->push(
            LiteralField::create(
                'StripeElements',
                '<div id="card-element" class="stripe-element"></div>'
            )
        );
        
        return $fields;
    }
} 