<?php

namespace Sunnysideup\Bookings\Forms;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Security;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\EmailField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextField;
use Sunnysideup\Bookings\Model\Booking;
use Sunnysideup\Bookings\Model\ReferralOption;
use Sunnysideup\Bookings\Model\Tour;
use Sunnysideup\Bookings\Model\TourBookingSettings;
use SilverStripe\Omnipay\Model\Payment;
use SilverStripe\Omnipay\Service\ServiceFactory;
use Exception;
use Psr\Log\LoggerInterface;
use Sunnysideup\Bookings\Model\PaymentConstants;
use Sunnysideup\Bookings\Exceptions\PaymentValidationException;
use Sunnysideup\Bookings\Exceptions\PaymentProcessingException;
use Sunnysideup\Bookings\Exceptions\PaymentGatewayException;
use SilverStripe\Core\Environment;
use SilverStripe\View\Requirements;
use Sunnysideup\Bookings\Logging\PaymentLogger;
use SilverStripe\Control\Director;

class TourBookingForm extends Form
{
    protected $currentBooking;

    protected $currentTour;

    private static $show_city_field_for_countries = [
        'NZ',
    ];

    // important note: $existingBooking and $singleTour should not both exist at the same time
    public function __construct($controller, $name, $existingBooking = null, $singleTour = null)
    {
        if ($existingBooking) {
            $this->currentBooking = $existingBooking;
            $bookingSingleton = $this->currentBooking;
        } else {
            $bookingSingleton = Injector::inst()->get(Booking::class);
        }

        if ($singleTour) {
            $this->currentTour = $singleTour;
        }

        $fields = $bookingSingleton->getFrontEndFields();

        $fieldList = FieldList::create();

        $column1 = CompositeField::create()->addExtraClass('always-show left-column');
        $column2 = CompositeField::create()->addExtraClass('right-column');

        if ($this->currentBooking) {
            $LeftColHeader = HeaderField::create('UpdateBookingHeader', 'Update your booking.', 5);
        } else {
            $LeftColHeader = HeaderField::create('LeftColHeader', _t('TourBookingForm.SELECT_DATE_AND_NUMBER_OF_GUESTS', 'Select your date and number of guests.'), 5);
        }

        $column1->push(
            $LeftColHeader
        );

        $column1->push(
            $guestsField = NumericField::create('TotalNumberOfGuests', 'Tell us how many people you\'d like to bring?')
                ->addExtraClass('always-show')
                ->setScale(0)
        );

        if (null === $this->currentTour) {
            $column1->push(
                $dateField = TextField::create('BookingDate', 'Select Your Date')
            );

            if ($existingBooking) {
                $column1->push(
                    HiddenField::create('CurrentBookingDate', 'Current Booking Date', $existingBooking->Date)
                );
            }
        }

        $column2->push(
            HeaderField::create('RightColHeader', 'Your personal details.', 5)
        );

        foreach ($fields as $field) {
            $column2->push($field);
        }

        //only for new bookings
        if ($existingBooking) {
            $fields->removeByName('CityTown');
        } else {
            $fields->dataFieldByName('CountryOfOrigin')->setValue('nz');
            //referral options
            $referralOptions = ReferralOption::get()->filter(['Archived' => false]);
            if (0 !== $referralOptions->count()) {
                $referralOptionsField = CheckboxSetField::create(
                    'ReferralOptions',
                    'How did you hear about our tours?',
                    $referralOptions->sort('SortOrder', 'ASC')->map('ID', 'Title')
                );

                $column2->push(
                    $referralOptionsField
                );

                $hasOther = ReferralOption::get()->filter(['IsOther' => true])->first();
                if (null !== $hasOther) {
                    $referralOptionsField->setAttribute('data-other', $hasOther->ID);

                    $column2->push(
                        TextField::create(
                            'ReferralText',
                            'Let us know more'
                        )
                    );
                }
            }
        }

        if ($this->currentBooking) {
            $fieldList->push(
                HiddenField::create('BookingCode', '', $this->currentBooking->Code)
            );
            $column2->removeByName('InitiatingEmail');
            $column2->push(
                EmailField::create(
                    'ConfirmingEmail',
                    'Confirm your Email'
                )
            );
        }

        if ($this->currentTour) {
            $column2->replaceField(
                'TourID',
                HiddenField::create(
                    'TourID',
                    'TourID',
                    $this->currentTour->ID
                )
            );
        }

        // If payments are enabled, include Stripe Elements container and hidden token field
        if (TourBookingSettings::inst()->EnablePayments) {
            $column2->push(HiddenField::create('stripeToken', ''));
            $column2->push(
                LiteralField::create(
                    'StripeCardElement',
                    '<div class="field field--stripe">'
                    . '<label>Pay by Credit Card</label>'
                    . '<div id="card-element"></div>'
                    . '<div id="card-errors" class="message bad" role="alert"></div>'
                    . '</div>'
                )
            );
                         // Add minimal styles to make card UI stand out
             Requirements::customCSS(
                 '.field--stripe{margin-top:20px;padding:18px;border:2px solid #111;border-radius:12px;background:#fff;box-shadow:0 6px 18px rgba(0,0,0,.06)}'
                 . '.field--stripe label{display:block;margin:0 0 10px;font-weight:700;letter-spacing:.02em}'
                 . '#card-element{padding:12px 14px;border:1px solid #d9d9d9;border-radius:8px;background:#fafafa}'
                 . '.StripeElement--focus{border-color:#d81b28;box-shadow:0 0 0 3px rgba(216,27,40,.15)}'
                 . '#card-errors{margin-top:10px;color:#d81b28;font-weight:600;padding:8px 12px;background:#fff3f3;border:1px solid #ffcdd2;border-radius:4px;display:none}'
                 . '#card-errors:not(:empty){display:block}',
                 'StripeElementsCSS'
             );

            // Load Stripe.js and boot minimal Elements integration
            $pk = (string) (Environment::getEnv('STRIPE_PUBLISHABLE_KEY') ?: '');
            if ($pk) {
                // Use a direct script tag to avoid local requirement logging/caching
                Requirements::insertHeadTags('<script src="https://js.stripe.com/v3/"></script>');
                $formIdMain = 'Form_' . $this->FormName();
                $formIdAlt = $this->FormName();
                $boot = <<<JS
                (function(){
                  function init(){
                    if (!window.Stripe) { return; }
                    var stripe = Stripe("{$pk}");
                    var elements = stripe.elements();
                    var card = elements.create('card');
                    var cardEl = document.getElementById('card-element');
                    if (!cardEl) { return; }
                    card.mount(cardEl);

                    // Track card completion status
                    var cardComplete = false;
                    card.on('change', function(event) {
                        cardComplete = event.complete;
                        var errorEl = document.getElementById('card-errors');
                        if (errorEl && event.error) {
                            errorEl.textContent = event.error.message;
                            errorEl.style.display = 'block';
                        } else if (errorEl && cardComplete) {
                            errorEl.textContent = '';
                            errorEl.style.display = 'none';
                        }
                    });
                    var form = document.getElementById('{$formIdMain}') || document.getElementById('{$formIdAlt}');
                    if (!form) { return; }
                    form.addEventListener('submit', function(e){
                      // Check if payment is required by looking for payment-related fields
                      var cardElement = document.getElementById('card-element');
                      var tokenInput = form.querySelector('input[name="stripeToken"]');
                      var stripeField = document.querySelector('.field--stripe');
                      var needsPayment = cardElement && stripeField && stripeField.style.display !== 'none' && cardElement.parentNode.style.display !== 'none';

                      // If payment element is not present, let the form submit normally
                      if (!needsPayment) { return; }

                      // If token is already set, let the form submit normally (avoid loops)
                      if (tokenInput && tokenInput.value) { return; }

                      // Prevent form submission to handle payment processing
                      e.preventDefault();

                      // Clear any previous errors
                      var errorEl = document.getElementById('card-errors');
                      if (errorEl) {
                        errorEl.textContent = '';
                        errorEl.style.display = 'none';
                      }

                      // Create Stripe token
                      stripe.createToken(card).then(function(result){
                        if (result.error) {
                          if (errorEl) {
                            errorEl.textContent = result.error.message;
                            errorEl.style.display = 'block';
                          }
                          // Show validation error in the payment section
                          var paymentSection = document.querySelector('.payment-section');
                          if (paymentSection) {
                            paymentSection.classList.add('validation-error');
                            var existingError = paymentSection.querySelector('.payment-validation-error');
                            if (!existingError) {
                              var errorMessage = document.createElement('div');
                              errorMessage.className = 'payment-validation-error validation-error';
                              errorMessage.style.cssText = 'color: #dc3545; font-size: 14px; margin-top: 8px; margin-bottom: 0; font-weight: 500; clear: both; display: block;';
                              errorMessage.textContent = 'Please complete your payment details to continue.';
                              paymentSection.appendChild(errorMessage);
                            }
                          }
                        } else {
                          if (tokenInput) { tokenInput.value = result.token.id; }
                          // Remove any validation errors
                          var paymentSection = document.querySelector('.payment-section');
                          if (paymentSection) {
                            paymentSection.classList.remove('validation-error');
                            var existingError = paymentSection.querySelector('.payment-validation-error');
                            if (existingError) {
                              existingError.remove();
                            }
                          }
                          form.submit();
                        }
                      });
                    });
                  }
                  if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', init);
                  } else { init(); }
                })();
                JS;
                Requirements::customScript($boot, 'StripeElementsBoot');
            }
        }

        $fieldList->push($column1);
        $fieldList->push($column2);

        $actions = FieldList::create(
            FormAction::create(
                'dobooking',
                'Finalise Booking'
            )
        );

        $validator = $bookingSingleton->getFrontEndValidator();

        parent::__construct($controller, $name, $fieldList, $actions, $validator);

        $formName = $this->FormName();
        $sessionKey = "FormInfo.{$formName}.data";
        $oldData = Controller::curr()->getRequest()->getSession()->get($sessionKey);

        // Debug logging to track session data loading
        PaymentLogger::info('form.session_data_load', [
            'formName' => $formName,
            'sessionKey' => $sessionKey,
            'hasSessionData' => !empty($oldData),
            'sessionDataType' => $oldData ? gettype($oldData) : 'null',
            'sessionDataKeys' => is_array($oldData) ? array_keys($oldData) : 'not_array',
            'hasCurrentBooking' => !empty($this->currentBooking),
        ]);

        $oldData = $oldData ?: $this->currentBooking;

        if ($oldData && (is_array($oldData) || is_object($oldData))) {
            $this->loadDataFrom($oldData);
            PaymentLogger::info('form.data_loaded', [
                'formName' => $formName,
                'dataSource' => is_array($oldData) ? 'session' : 'currentBooking',
            ]);
        }

        return $this;
    }

    /**
     * Form action handler for TourBookingForm.
     *
     * @param array $data The form request data submitted
     * @param Form  $form The {@link Form} this was submitted on
     */
    public function dobooking(array $data, Form $form, HTTPRequest $request)
    {
        PaymentLogger::info('booking.submit', [
            'route' => 'dobooking',
            'bookingCode' => $this->currentBooking ? $this->currentBooking->Code : null,
            'data_keys' => array_keys($data ?? []),
        ]);
        $newBooking = true;
        $this->saveDataToSession();

        // Store the actual form session key for later clearing (needed for 3DS flow)
        $sessionKey = "FormInfo.{$this->FormName()}.data";
        Controller::curr()->getRequest()->getSession()->set("BookingFormSessionKey", $sessionKey);

        $data = Convert::raw2sql($data);

        if (isset($data['TourID']) && $data['TourID']) {
            $selectedTour = Tour::get_by_id($data['TourID']);

            if ($selectedTour && !$selectedTour->getAllowBooking()) {
                PaymentLogger::error('booking.validation.fail', [
                    'reason' => 'tour_not_accepting_bookings',
                    'tourID' => $selectedTour ? $selectedTour->ID : null,
                ]);
                $this->sessionError(
                    'Sorry, the tour is no-longer accepting booking at the selected time. Please book a tour at a different time.',
                    'bad'
                );

                return $this->controller->redirectBack();
            }
        }

        // Handle ticket types for new booking system
        $quantities = [];
        $totalGuests = 0;

        // Parse ticket type quantities from form data
        foreach ($data as $fieldName => $value) {
            if (strpos($fieldName, 'TicketType_') === 0 && strpos($fieldName, '_Quantity') !== false) {
                $parts = explode('_', $fieldName);
                if (count($parts) >= 3 && is_numeric($parts[1])) {
                    $ticketTypeId = (int) $parts[1];
                    $quantity = (int) $value;

                    if ($quantity > 0) {
                        $quantities[$ticketTypeId] = $quantity;
                    }
                }
            }
        }

        // Validate ticket types
        if (!empty($quantities)) {
            $totalAdults = 0;
            $totalKids = 0;

            foreach ($quantities as $ticketTypeId => $quantity) {
                $ticketType = \Sunnysideup\Bookings\Model\TicketType::get()->byID($ticketTypeId);
                if ($ticketType) {
                    $adultsForThisType = $ticketType->SpotsAdults * $quantity;
                    $kidsForThisType = $ticketType->SpotsKids * $quantity;

                    $totalAdults += $adultsForThisType;
                    $totalKids += $kidsForThisType;
                    $totalGuests += $adultsForThisType + $kidsForThisType;
                }
            }

            // Validate: No children without adults
            if ($totalKids > 0 && $totalAdults === 0) {
                PaymentLogger::error('booking.validation.fail', [
                    'reason' => 'kids_without_adults',
                ]);
                $this->sessionError('Children must be with an adult.', 'bad');
                return $this->controller->redirectBack();
            }

            // Validate: At least one spot selected
            if ($totalGuests === 0) {
                PaymentLogger::error('booking.validation.fail', [
                    'reason' => 'no_tickets_selected',
                ]);
                $this->sessionError('Please select at least one ticket.', 'bad');
                return $this->controller->redirectBack();
            }
        } else {
            // Fallback to old TotalNumberOfGuests field if no ticket types
            $totalGuests = (int) $data['TotalNumberOfGuests'];
        }

        // Validate against tour availability
        if ($this->currentTour) {
            $spacesLeft = $this->currentTour->getNumberOfPlacesAvailable()->value;
            if ($totalGuests > $spacesLeft) {
                $message = 'Sorry, there are no spaces left.';
                if ($spacesLeft > 1) {
                    $message = 'Sorry there are only ' . $spacesLeft . ' spaces';
                } elseif ($spacesLeft === 1) {
                }
                PaymentLogger::error('booking.validation.fail', [
                    'reason' => 'not_enough_spaces',
                    'spacesLeft' => $spacesLeft,
                    'totalGuests' => $totalGuests,
                ]);
                $this->sessionError(
                    $message . ' left. Please reduce the number of people for your booking or book a tour at a different time.',
                    'bad'
                );

                return $this->controller->redirectBack();
            }
        }

        // Guest validations for unsaved objects (moved from Booking->validate())
        if ($totalGuests < 1) {
            PaymentLogger::error('booking.validation.fail', [
                'reason' => 'no_adult_attending',
                'totalKids' => $totalKids,
            ]);
            $this->sessionError(
                'You need to have at least one person attending to make a booking.',
                'bad'
            );
            return $this->controller->redirectBack();
        }

        // Check for children without adults
        $totalKids = 0;
        if (!empty($quantities)) {
            foreach ($quantities as $ticketTypeId => $quantity) {
                $ticketType = \Sunnysideup\Bookings\Model\TicketType::get()->byID($ticketTypeId);
                if ($ticketType) {
                    $totalKids += $ticketType->SpotsKids * $quantity;
                }
            }
        } else {
            // Fallback to old system for NumberOfChildren
            $totalKids = (int) ($data['NumberOfChildren'] ?? 0);
        }

        if ($totalKids > 0 && ($totalGuests - $totalKids) < 1) {
            $this->sessionError(
                'You need to have at least one adult attending. It appears you only have children listed for this booking.',
                'bad'
            );
            return $this->controller->redirectBack();
        }

        // Validate peanut allergy confirmation
        if (!isset($data['PeanutAllergyConfirmation']) || !(bool) $data['PeanutAllergyConfirmation']) {
            PaymentLogger::error('booking.validation.fail', [
                'reason' => 'peanut_allergy_not_confirmed',
            ]);
            $this->sessionError(
                'You must confirm that no one in your group is allergic to peanuts.',
                'bad'
            );
            return $this->controller->redirectBack();
        }

        // Validate referral options only for new bookings (update forms may hide this field)
        if (empty($data['ReferralOptions']) && !$this->currentBooking) {
            PaymentLogger::error('booking.validation.fail', [
                'reason' => 'no_referral_options',
            ]);
            $this->sessionError(
                'Please select how you heard about our tours.',
                'bad'
            );
            return $this->controller->redirectBack();
        }

        if ($this->currentBooking) {
            $newBooking = false;
            // Skip email validation for update bookings since the email field is hidden
            // The email will remain unchanged from the original booking
        } else {
            $this->currentBooking = Booking::create();
        }

        $form->saveInto($this->currentBooking);

        // Update TotalNumberOfGuests to reflect the actual total from ticket types
        if (!empty($quantities)) {
            $this->currentBooking->TotalNumberOfGuests = $totalGuests;

            // Calculate and set NumberOfChildren from ticket types
            $totalKids = 0;
            foreach ($quantities as $ticketTypeId => $quantity) {
                $ticketType = \Sunnysideup\Bookings\Model\TicketType::get()->byID($ticketTypeId);
                if ($ticketType) {
                    $totalKids += $ticketType->SpotsKids * $quantity;
                }
            }
            $this->currentBooking->NumberOfChildren = $totalKids;
        }

        $validationObject = $this->currentBooking->validate($quantities);
        if (! $validationObject->isValid()) {
            foreach ($validationObject->getMessages() as $message) {
                $this->sessionError(
                    $message['message'] . ' ',
                );
            }

            return $this->controller->redirectBack();
        }

        // Save ticket types if any were selected
        if (!empty($quantities)) {
            $this->currentBooking->saveTicketTypes($quantities);
        }

        if (isset($data['ReferralOptions'])) {
            foreach ($data['ReferralOptions'] as $referralOptionID) {
                $referralOptionID = (int) $referralOptionID;
                $referralOption = ReferralOption::get()->byID($referralOptionID);
                if (null !== $referralOption) {
                    $this->currentBooking->ReferralOptions()->add($referralOption);
                }
            }
        }
        if (isset($data['ReferralText'])) {
            $this->currentBooking->ReferralText = $data['ReferralText'];
        }
        $this->currentBooking->write();
        PaymentLogger::info('booking.saved', [
            'bookingID' => $this->currentBooking->ID,
            'bookingCode' => $this->currentBooking->Code,
            'totalGuests' => (int) $this->currentBooking->getField('TotalNumberOfGuests'),
        ]);
        //$this->currentBooking->Tour()->write();
        $settings = TourBookingSettings::inst();

        // Handle payment if required and enabled
        if ($this->isPaymentEnabled() && $this->currentBooking->requiresPayment()) {
            PaymentLogger::info('payment.initiate', [
                'bookingID' => $this->currentBooking->ID,
                'bookingCode' => $this->currentBooking->Code,
                'amount' => $this->currentBooking->getPaymentAmount(),
                'currency' => 'NZD',
            ]);
            $paymentResult = $this->processPayment($this->currentBooking, $data);
            if (!$paymentResult['success']) {
                PaymentLogger::error('payment.failed', [
                    'bookingID' => $this->currentBooking->ID,
                    'bookingCode' => $this->currentBooking->Code,
                    'error' => $paymentResult['message'] ?? 'unknown',
                ]);
                $this->currentBooking->delete();
                $this->sessionError($paymentResult['message'], 'bad');
                return $this->controller->redirectBack();
            }

            // Handle 3D Secure redirect
            if (isset($paymentResult['redirect'])) {
                PaymentLogger::info('payment.redirect', [
                    'bookingID' => $this->currentBooking->ID,
                    'bookingCode' => $this->currentBooking->Code,
                    'redirect' => $paymentResult['redirect'],
                ]);
                return $this->controller->redirect($paymentResult['redirect']);
            }
        } else {
            // For free bookings (no payment required), mark as paid
            if (!$this->currentBooking->requiresPayment()) {
                if ($this->currentBooking->isOfflinePaymentUser()) {
                    // Offline payment user - set specific payment details
                    $member = Security::getCurrentUser();
                    $userEmail = $member ? $member->Email : 'unknown@offline.local';
                    
                    PaymentLogger::info('booking.offline_payment_mark_paid', [
                        'bookingID' => $this->currentBooking->ID,
                        'bookingCode' => $this->currentBooking->Code,
                        'userEmail' => $userEmail,
                        'reason' => 'offline_payment_user',
                    ]);
                    
                    $this->currentBooking->updatePaymentStatus('Paid', [
                        'gateway' => 'Offline',
                        'reference' => $userEmail,
                    ]);
                } else {
                    // Regular free booking
                    PaymentLogger::info('booking.free_booking_mark_paid', [
                        'bookingID' => $this->currentBooking->ID,
                        'bookingCode' => $this->currentBooking->Code,
                        'reason' => 'no_payment_required',
                    ]);
                    $this->currentBooking->updatePaymentStatus('Paid');
                }
            }
        }

        if ($newBooking) {
            $confirmationEmail = $settings->BookingConfirmationEmail();
            $confirmationEmail->sendOne($this->currentBooking);
        } else {
            $confirmationEmail = $settings->UpdateConfirmationEmail();
            $confirmationEmail->sendOne($this->currentBooking);
        }

        $redirect = $this->currentBooking->ConfirmLink();

        // Clear session data after successful booking to prevent form repopulation
        $this->clearFormState();

        PaymentLogger::info('booking.redirect.confirm', [
            'bookingID' => $this->currentBooking->ID,
            'bookingCode' => $this->currentBooking->Code,
            'redirect' => $redirect,
        ]);
        return $this->controller->redirect($redirect);
    }

    /**
     * saves the form into session.
     */
    public function saveDataToSession()
    {
        $data = $this->getData();
        $sessionKey = "FormInfo.{$this->FormName()}.data";

        Controller::curr()->getRequest()->getSession()->set($sessionKey, $data);

        // Log the session save for debugging
        PaymentLogger::info('form.session_data_save', [
            'formName' => $this->FormName(),
            'sessionKey' => $sessionKey,
            'dataKeys' => is_array($data) ? array_keys($data) : 'not_array',
        ]);
    }



    /**
     * Check if payment is enabled in settings
     */
    protected function isPaymentEnabled(): bool
    {
        return TourBookingSettings::inst()->EnablePayments;
    }

    /**
     * Process payment for booking
     */
    protected function processPayment(Booking $booking, array $data): array
    {
        $logger = Injector::inst()->get(LoggerInterface::class);

        try {
            PaymentLogger::info('payment.create', [
                'bookingID' => $booking->ID,
                'bookingCode' => $booking->Code,
            ]);
            // Validation: Check payment amount
            $paymentAmount = $booking->getPaymentAmount();
            if (!$booking->validatePaymentAmount($paymentAmount)) {
                throw new PaymentValidationException($booking->getAmountValidationError($paymentAmount));
            }

            // Validation: Check payment token
            if (!isset($data['stripeToken']) || empty($data['stripeToken'])) {
                throw new PaymentValidationException('Please enter your payment details to complete the booking.');
            }

            // Get currency from configuration
            $currency = $booking->getPaymentCurrency();

            // If amount due is zero, skip creating an Omnipay payment entirely
            if ($paymentAmount <= 0) {
                return ['success' => true];
            }

            // Create payment via SilverStripe Omnipay using Payment Intents API
            $payment = Payment::create()
                ->init(PaymentConstants::GATEWAY_STRIPE_PAYMENT_INTENTS, $paymentAmount, $currency)
                ->setSuccessUrl($booking->ConfirmLink())
                ->setFailureUrl($this->controller->Link('paymentfailure') . '?booking=' . $booking->getShortCode());

            $payment->write();

            // Link Payment to Booking
            $payment->BookingID = $booking->ID;
            $payment->write();

            PaymentLogger::info('payment.persisted', [
                'paymentID' => $payment->ID,
                'paymentIdentifier' => $payment->Identifier,
                'gateway' => PaymentConstants::GATEWAY_STRIPE,
                'amount' => $paymentAmount,
                'currency' => $currency,
                'bookingID' => $booking->ID,
            ]);

            // Process payment with Stripe token using Payment Intents API for better 3DS support
            $service = ServiceFactory::create()->getService($payment, ServiceFactory::INTENT_PURCHASE);
            PaymentLogger::info('payment.gateway.request', [
                'paymentID' => $payment->ID,
                'token_present' => isset($data['stripeToken']) && !empty($data['stripeToken']),
            ]);

            // Force 3DS testing by using Payment Intents API with specific parameters
            $response = $service->initiate([
                'token' => $data['stripeToken'],
                'description' => 'Booking: ' . $booking->Code,
                'receipt_email' => $booking->InitiatingEmail, // Add receipt email
                'confirm' => true, // Force immediate confirmation which triggers 3DS if needed
                'setupFutureUsage' => 'off_session', // This can trigger 3DS
                'metadata' => [
                    'booking_id' => $booking->ID,
                    'booking_code' => $booking->Code
                ]
            ]);

            if ($response->isSuccessful()) {
                // Get Payment Intent ID for admin display
                $omnipayResponse = $response->getOmnipayResponse();
                $paymentIntentRef = $omnipayResponse && method_exists($omnipayResponse, 'getPaymentIntentReference')
                    ? $omnipayResponse->getPaymentIntentReference()
                    : null;

                if ($paymentIntentRef) {
                    $payment->PaymentIntentId = $paymentIntentRef;
                    $payment->write();
                }

                PaymentLogger::info('payment.gateway.success', [
                    'paymentID' => $payment->ID,
                    'paymentIntentReference' => $paymentIntentRef,
                ]);
                // Payment succeeded immediately - no 3DS required
                // Let Omnipay handle the status update via our extension
                return ['success' => true];
            } elseif ($response->isRedirect()) {
                // Get Payment Intent ID for admin display and 3DS completion
                $omnipayResponse = $response->getOmnipayResponse();
                $paymentIntentRef = $omnipayResponse && method_exists($omnipayResponse, 'getPaymentIntentReference')
                    ? $omnipayResponse->getPaymentIntentReference()
                    : null;

                if ($paymentIntentRef) {
                    $payment->PaymentIntentId = $paymentIntentRef;
                    $payment->write();
                }

                PaymentLogger::info('payment.gateway.redirect', [
                    'paymentID' => $payment->ID,
                    'redirectUrl' => $response->getTargetUrl(),
                    'paymentIntentReference' => $paymentIntentRef,
                ]);
                // 3D Secure required - redirect to Stripe
                // Omnipay will handle the completion via PaymentGatewayController
                return ['success' => true, 'redirect' => $response->getTargetUrl()];
            } else {
                $omni = method_exists($response, 'getOmnipayResponse') ? $response->getOmnipayResponse() : null;
                $msg = ($omni && method_exists($omni, 'getMessage')) ? (string) $omni->getMessage() : 'Unknown gateway error';
                PaymentLogger::error('payment.gateway.error', [
                    'paymentID' => $payment->ID,
                    'message' => $msg,
                ]);
                throw new PaymentProcessingException('Payment failed: ' . $msg);
            }

        } catch (PaymentValidationException $e) {
            $logger->warning('Payment validation error for booking ' . $booking->Code, [
                'booking_id' => $booking->ID,
                'error' => $e->getAdminMessage()
            ]);
            PaymentLogger::error('payment.validation.error', [
                'bookingID' => $booking->ID,
                'bookingCode' => $booking->Code,
                'message' => $e->getAdminMessage(),
            ]);
            return ['success' => false, 'message' => $e->getUserMessage()];

        } catch (PaymentGatewayException $e) {
            $logger->error('Payment gateway error for booking ' . $booking->Code, [
                'booking_id' => $booking->ID,
                'error' => $e->getAdminMessage()
            ]);
            PaymentLogger::error('payment.gateway.exception', [
                'bookingID' => $booking->ID,
                'bookingCode' => $booking->Code,
                'message' => $e->getAdminMessage(),
            ]);
            return ['success' => false, 'message' => $e->getUserMessage()];

        } catch (PaymentProcessingException $e) {
            $logger->error('Payment processing error for booking ' . $booking->Code, [
                'booking_id' => $booking->ID,
                'error' => $e->getAdminMessage()
            ]);
            PaymentLogger::error('payment.processing.exception', [
                'bookingID' => $booking->ID,
                'bookingCode' => $booking->Code,
                'message' => $e->getAdminMessage(),
            ]);
            return ['success' => false, 'message' => $e->getUserMessage()];

        } catch (Exception $e) {
            // Catch-all for unexpected errors - log but don't expose details
            $logger->critical('Unexpected payment error for booking ' . $booking->Code, [
                'booking_id' => $booking->ID,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            PaymentLogger::error('payment.exception', [
                'bookingID' => $booking->ID,
                'bookingCode' => $booking->Code,
                'message' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'message' => 'Payment processing failed. Please check your payment details and try again.'
            ];
        }
    }
}
