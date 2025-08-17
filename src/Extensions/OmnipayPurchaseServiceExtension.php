<?php

namespace Sunnysideup\Bookings\Extensions;

use SilverStripe\Control\Controller;
use SilverStripe\Core\Extension;
use Sunnysideup\Bookings\Model\PaymentConstants;

/**
 * Ensures Stripe Payment Intents completion receives the expected parameter name
 * and updates the related booking status after successful payment.
 *
 * Maps `payment_intent` (from Stripe) to `paymentIntentReference` (expected by omnipay/stripe PI completePurchase).
 *
 * @property PurchaseService|OmnipayPurchaseServiceExtension $owner
 */
class OmnipayPurchaseServiceExtension extends Extension
{
    /**
     * Before completing purchase, normalise PI parameter name.
     *
     * @param array $gatewayData (passed by reference by SilverStripe extension system)
     */
    public function onBeforeCompletePurchase(&$gatewayData): void
    {
        $owner = $this->owner; // SilverStripe\Omnipay\Service\PurchaseService
        if (!$owner || !method_exists($owner, 'getPayment')) {
            return;
        }

        $payment = $owner->getPayment();
        if (!$payment || $payment->Gateway !== PaymentConstants::GATEWAY_STRIPE_PAYMENT_INTENTS) {
            return;
        }

        if (!isset($gatewayData['paymentIntentReference']) || empty($gatewayData['paymentIntentReference'])) {
            $request = Controller::curr() ? Controller::curr()->getRequest() : null;
            $piFromQuery = $request ? ($request->getVar('payment_intent') ?: $request->getVar('paymentIntentReference')) : null;
            if ($piFromQuery) {
                $gatewayData['paymentIntentReference'] = $piFromQuery;
            }
        }
    }


}


