<?php

namespace Sunnysideup\Bookings\Extensions;

use SilverStripe\ORM\DataExtension;
use Sunnysideup\Bookings\Model\Booking;

/**
 * Extension for SilverStripe\Omnipay\Model\Payment to add relationship back to Booking
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
}
