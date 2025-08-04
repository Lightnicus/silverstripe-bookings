<?php

namespace Sunnysideup\Bookings\Extensions;

use SilverStripe\ORM\DataExtension;
use Sunnysideup\Bookings\Model\TourBookingSettings;

/**
 * Class \Sunnysideup\Bookings\Extensions\BookingPaymentExtension
 *
 * @property Booking|BookingPaymentExtension $owner
 * @property string $PaymentStatus
 * @property string $PaymentAmount
 * @property string $PaymentGateway
 * @property string $PaymentReference
 * @property string $PaymentDate
 * @property string $PaymentIntentId
 * @method DataList|Payment[] Payments()
 */
class BookingPaymentExtension extends DataExtension
{
    private static $db = [
        'PaymentStatus' => "Enum('Pending,Paid,Failed,Refunded,Cancelled','Pending')",
        'PaymentAmount' => 'Money',
        'PaymentGateway' => 'Varchar(50)',
        'PaymentReference' => 'Varchar(255)',
        'PaymentDate' => 'Datetime',
        'PaymentIntentId' => 'Varchar(255)', // For Stripe Payment Intent tracking
    ];
    
    private static $has_many = [
        'Payments' => 'SilverStripe\Omnipay\Model\Payment',
    ];
    
    public function requiresPayment(): bool
    {
        return $this->owner->getTotalPriceFromTicketTypes() > 0;
    }
    
    public function getPaymentAmount(): float
    {
        return $this->requiresPayment() ? $this->owner->getTotalPriceFromTicketTypes() : 0;
    }
    
    public function isPaymentComplete(): bool
    {
        return $this->owner->PaymentStatus === 'Paid';
    }
    
    public function canProceedWithoutPayment(): bool
    {
        return !$this->requiresPayment() || $this->isPaymentComplete();
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
} 