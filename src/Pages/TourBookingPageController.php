<?php

namespace Sunnysideup\Bookings\Pages;

use PageController;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\DataList;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\View\ArrayData;
use SilverStripe\View\Requirements;
use Sunnysideup\Bookings\Cms\TourBookingsAdmin;
use Sunnysideup\Bookings\Forms\SelfCheckInForm;
use Sunnysideup\Bookings\Forms\TourBookingCancellationForm;
use Sunnysideup\Bookings\Forms\TourBookingForm;
use Sunnysideup\Bookings\Forms\TourWaitlistForm;
use Sunnysideup\Bookings\Model\Booking;
use Sunnysideup\Bookings\Model\DateInfo;
use Sunnysideup\Bookings\Model\Tour;
use Sunnysideup\Bookings\Model\TourBookingSettings;
use Sunnysideup\Bookings\Model\Waitlister;
use SilverStripe\Omnipay\Model\Payment;
use SilverStripe\Omnipay\Service\ServiceFactory;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Environment;
use Psr\Log\LoggerInterface;
use Sunnysideup\Bookings\Model\PaymentConstants;
use Exception;

/**
 * Class \Sunnysideup\Bookings\Pages\TourBookingPageController
 *
 * @property TourBookingPage $dataRecord
 * @method TourBookingPage data()
 * @mixin TourBookingPage
 */
class TourBookingPageController extends PageController
{
    protected $isCancellation = false;

    protected $locationIP = '';

    //######################
    // revivew
    //######################

    protected $listOfToursFromDate;

    protected $listOfToursUntilDate;

    private static $url_segment = 'tour-bookings';

    private static $allowed_actions = [
        //add
        'BookingForm' => true,
        'signup' => true,
        'availability' => true,
        'confirmsignup' => true,

        //edit
        'BookingCancellationForm' => true,
        'update' => '->canEdit',
        'cancel' => true,

        //waiting list
        'WaitlistForm' => true,
        'waitlist' => true,
        'confirmwaitlist' => true,
        'SingleTourBookingForm' => true,
        'jointour' => true,

        //review / lists
        'calendar' => true,
        'today' => 'CMS_ACCESS_TOUR_ADMIN',
        'tomorrow' => 'CMS_ACCESS_TOUR_ADMIN',
        'nextdays' => 'CMS_ACCESS_TOUR_ADMIN',
        'all' => 'CMS_ACCESS_TOUR_ADMIN',
        'quickview' => 'CMS_ACCESS_TOUR_ADMIN',

        //on the day
        'checkinfortour' => 'CMS_ACCESS_TOUR_ADMIN',
        'confirmonecheckin' => 'CMS_ACCESS_TOUR_ADMIN',

        //on the day
        'SelfCheckInForm' => true,
        'selfcheckin' => true,
        'confirmselfcheckin' => true,

        //payment
        'paymentcallback' => true,
        'paymentwebhook' => true,
        'paymentsuccess' => true,
        'paymentfailure' => true,
    ];

    //######################
    // add a booking
    //######################

    private $availabilityDateAsTS;

    private $bookingCode = '';

    private $totalNumberOfGuests = 0;

    private $currentBooking;

    //######################
    // join the waitlist
    //######################

    private $currentWaitlister;

    //######################
    // on the day
    //######################

    private $currentTour;

    /**
     * called when no other action is called
     * redirects to start sign up process.
     *
     * @param mixed $request
     */
    public function index($request)
    {
        if (TourBookingPage::class === $this->ClassName) {
            return $this->redirect($this->Link('signup'));
        }

        return ['Content' => DBField::create_field('HTMLText', $this->Content)];
    }

    public function canEdit($member = null, $context = [])
    {
        return $this->currentBooking && $this->currentBooking->exists();
    }

    public function CurrentUserIsTourManager($member)
    {
        return (bool) Permission::check('CMS_ACCESS_TOUR_ADMIN', 'any', $member);
    }

    public function BookingForm($request = null)
    {
        $this->getBookingFromRequestOrIDParam();

        return TourBookingForm::create($this, 'BookingForm', $this->currentBooking);
    }

    public function signup($request)
    {
        $this->Content = $this->BookingForm();
        if ($this->IsOnLocation()) {
            return $this->RenderWith(['Page_MainOnly', 'Page']);
        }

        return $this->RenderWith(['Page']);
    }

    public function availability($request)
    {
        $dateAsString = $request->getVar('date');
        $this->totalNumberOfGuests = (int) $request->getVar('guests');
        // hack!
        // $dateAsString = str_replace(' (New Zealand Standard Time)', '', $dateAsString);
        $dateAsString = preg_replace('#\\([^)]+\\)#', '', $dateAsString);
        $this->availabilityDateAsTS = strtotime((string) $dateAsString);

        $this->bookingCode = Convert::raw2sql($request->getVar('bookingcode'));
        if ($this->bookingCode) {
            $this->currentBooking = Booking::get()->filter(['Code' => $this->bookingCode])->first();
        }

        return $this->RenderWith('Sunnysideup/Bookings/Includes/TourBookingsAvailableForOneDay');
    }

    public function confirmsignup($request)
    {
        if (!$this->currentBooking) {
            return $this->httpError(404, 'Not Found');
        }

        $this->Content = $this->RenderWith('Sunnysideup/Bookings/Includes/BookingConfirmationContent');

        if ($this->IsOnLocation()) {
            return $this->RenderWith(['Page_MainOnly', 'Page']);
        }

        return $this->RenderWith(['Page']);
    }

    public function DateInformation()
    {
        return DateInfo::best_match_for_date($this->availabilityDateAsTS);
    }

    public function MyDate()
    {
        return DBField::create_field('Date', date('Y-m-d', $this->availabilityDateAsTS));
    }

    public function ListOfToursForOneDay()
    {
        return $this->findTours($this->availabilityDateAsTS, $this->totalNumberOfGuests);
    }

    public function CurrentBooking()
    {
        return $this->currentBooking;
    }

    public function TotalNumberOfGuests()
    {
        return $this->totalNumberOfGuests;
    }

    //######################
    // edit or cancel booking
    //######################

    public function BookingCancellationForm()
    {
        $bookingCode = empty($this->currentBooking) ? 0 : $this->currentBooking->Code;

        return TourBookingCancellationForm::create($this, 'BookingCancellationForm', $bookingCode);
    }

    public function IsCancelled(): bool
    {
        if (!empty($this->currentBooking)) {
            return (bool) $this->currentBooking->Cancelled;
        }

        return false;
    }

    public function update($request)
    {
        if (!$this->currentBooking) {
            return $this->httpError(404, 'Not Found');
        }

        if ($this->IsCancelled()) {
            $this->Title = 'Cancellation Confirmation';
            $this->Content = $this->RenderWith('Sunnysideup/Bookings/Includes/BookingCancellationContent');
        } else {
            $this->Title = 'Update your booking';
            $this->Content = $this->RenderWith('Sunnysideup/Bookings/Includes/UpdateBookingContent');
        }

        if ($this->IsOnLocation()) {
            return $this->RenderWith(['Page_MainOnly', 'Page']);
        }

        return $this->RenderWith(['Page']);
    }

    public function cancel($request)
    {
        if (!$this->currentBooking) {
            return $this->httpError(404, 'Not Found');
        }

        $this->isCancellation = true;
        $this->Title = 'Cancel your booking';
        if ($this->IsCancelled()) {
            $this->Title = 'Cancellation Confirmation';
        }
        $this->Content = $this->RenderWith('Sunnysideup/Bookings/Includes/BookingCancellationContent');

        if ($this->IsOnLocation()) {
            return $this->RenderWith(['Page_MainOnly', 'Page']);
        }

        return $this->RenderWith(['Page']);
    }

    public function CurrentWaitlister()
    {
        return $this->currentWaitlister;
    }

    public function WaitlistForm($request = null)
    {
        $this->getTourFromRequestOrIDParam();
        $this->getNumberOfGuestsFromRequestOrIDParam();
        return TourWaitlistForm::create($this, 'WaitlistForm', $this->currentTour, $this->totalNumberOfGuests);
    }

    public function SingleTourBookingForm($request = null)
    {
        $this->getTourFromRequestOrIDParam();
        return TourBookingForm::create($this, 'SingleTourBookingForm', null, $this->currentTour);
    }

    public function waitlist($request)
    {
        $this->getNumberOfGuestsFromRequestOrIDParam();
        $this->Title = 'Join the Waitlist';
        $this->Content = $this->WaitlistForm();

        if ($this->IsOnLocation()) {
            return $this->RenderWith(['Page_MainOnly', 'Page']);
        }

        return $this->RenderWith(['Page']);
    }

    public function confirmwaitlist($request)
    {
        $code = Convert::raw2sql($this->request->param('ID'));

        $this->currentWaitlister = Waitlister::get()->filter(['Code' => $code])->last();

        if (!$code || !$this->currentWaitlister) {
            return $this->httpError(404, 'Not Found');
        }

        if ($waitlistSuccessMsg = $this->getWaitlistSuccessMessage()) {
            $this->currentWaitlister->waitlistSuccessMsg = str_replace(
                "[first_name]",
                $this->currentWaitlister->InitiatingFirstName,
                $waitlistSuccessMsg
            );
        }

        $this->Title = 'Confirmation';
        $this->Content = $this->RenderWith('Sunnysideup/Bookings/Includes/WaitlistConfirmationContent');

        if ($this->IsOnLocation()) {
            return $this->RenderWith(['Page_MainOnly', 'Page']);
        }

        return $this->RenderWith(['Page']);
    }

    public function jointour($request)
    {
        $this->getTourFromRequestOrIDParam();
        if (!$this->currentTour) {
            return $this->httpError(404, 'Tour not found');
        }
        $spacesLeft = $this->currentTour->getNumberOfPlacesAvailable()->value;
        $this->Title = $this->currentTour->getTitle();

        if ($spacesLeft > 0) {
            $this->Content = $this->SingleTourBookingForm();
        } else {
            $this->Content = $this->RenderWith('Sunnysideup/Bookings/Includes/TourFullMessage');
        }

        if ($this->IsOnLocation()) {
            return $this->RenderWith(['Page_MainOnly', 'Page']);
        }

        return $this->RenderWith(['Page']);
    }

    public function TourFullMessage()
    {
        $settings = TourBookingSettings::inst();

        return $settings->TourFullMessage;
    }

    public function ConfirmationPageContent()
    {
        $settings = TourBookingSettings::inst();

        return $settings->ConfirmationPageContent;
    }

    public function getWaitlistSuccessMessage()
    {
        $settings = TourBookingSettings::inst();

        return (isset($settings->WaitlistSuccessMessage) ? $settings->WaitlistSuccessMessage : null);
    }

    public function calendar($request)
    {
        $member = Security::getCurrentUser();
        if (null === $member) {
            return Security::permissionFailure($this);
        }
        if (Permission::checkMember($member, 'CMS_ACCESS_TOUR_ADMIN')) {
            $this->Content = $this->RenderWith('Sunnysideup/Bookings/Includes/CalendarView');

            return $this->RenderWith(['Sunnysideup/Bookings/Layout/CalendarPage']);
        }
        echo '
            Sorry you don\'t have the required permissions to access this area,
            please login with the right credentials. <a href="/Security/logout">LOG OUT NOW?</a>';
    }

    public function all($request)
    {
        $this->listOfToursFromDate = date('Y-m-d', strtotime('today'));
        $this->listOfToursUntilDate = date('Y-m-d', strtotime('+1 years'));

        return json_encode(array_merge($this->ClosedDatesAsArray(), $this->TourDateAsArray()));
    }

    public function today($request)
    {
        $this->listOfToursFromDate = date('Y-m-d');
        $this->listOfToursUntilDate = date('Y-m-d');
    }

    public function tomorrow($request)
    {
        $this->listOfToursFromDate = date('Y-m-d', strtotime('tomorrow'));
        $this->listOfToursUntilDate = date('Y-m-d', strtotime('tomorrow'));
    }

    public function nextdays($request)
    {
        $numberOfDays = (int) $request->param('ID');
        if (0 === $numberOfDays) {
            $numberOfDays = 7;
        }
        $this->listOfToursFromDate = date('Y-m-d', strtotime('today'));
        $this->listOfToursUntilDate = date('Y-m-d', strtotime('+ ' . $numberOfDays . ' days'));
    }

    public function ListOfTours(): DataList
    {
        return Tour::get()->filter(
            [
                'Date:GreaterThanOrEqual' => $this->listOfToursFromDate,
                'Date:LessThanOrEqual' => $this->listOfToursUntilDate,
            ]
        );
    }

    public function TourDateAsArray(): array
    {
        $tours = $this->ListOfTours();
        $tourData = [];
        foreach ($tours as $tour) {
            $array = [];
            $array['title'] = $tour->FullCalendarTitle();
            $array['abrv-title'] = $tour->AbrvCalendarTitle();
            $array['url'] = $this->Link('checkinfortour/' . $tour->ID . '/');
            $array['start'] = $tour->Date . 'T' . $tour->StartTime;
            $array['end'] = $tour->Date . 'T' . $tour->EndTimeObj()->Value;
            $array['backgroundColor'] = '#16a335';
            if ($tour->IsFull()->value) {
                $array['backgroundColor'] = '#e83333';
            }
            $tourData[] = $array;
        }

        return $tourData;
    }

    public function ClosedDatesAsArray(): array
    {
        $closedData = [];
        for ($i = 1; $i <= 365; ++$i) {
            $dateTS = strtotime('today +' . $i . ' day');
            $dateInfo = DateInfo::best_match_for_date($dateTS);
            if ($dateInfo->NoTourTimes) {
                $mysqlDate = date('Y-m-d', $dateTS);
                $title = $dateInfo->PublicContent ? $dateInfo->dbObject('PublicContent')->Summary(10) : 'Closed';
                $array = [];
                $array['title'] = $title;
                $array['abrv-title'] = $title;
                $array['start'] = $mysqlDate . 'T00:00:00';
                $array['end'] = $mysqlDate . 'T23:59:00';
                $array['backgroundColor'] = '#007bff';
                $closedData[] = $array;
            }
        }

        return $closedData;
    }

    public function quickview($request)
    {
        $this->getTourFromRequestOrIDParam();
        if (!$this->currentTour) {
            return $this->httpError(404, 'Tour not found');
        }

        return $this->RenderWith('Sunnysideup/Bookings/Includes/QuickView');
    }

    public function checkinfortour($request)
    {
        $this->getTourFromRequestOrIDParam();
        if (!$this->currentTour) {
            return $this->httpError(404, 'Tour not found');
        }

        $this->Content = $this->RenderWith('Sunnysideup/Bookings/Includes/TourCheckinContent');

        return $this->RenderWith(['Page']);
    }

    public function confirmonecheckin($request)
    {
        $booking = Booking::get()->byID((int) $request->getVar('id'));
        $booking->HasArrived = Convert::raw2sql($request->getVar('arrived'));

        return $booking->write();
    }

    public function SelfCheckInForm($request = null)
    {
        return SelfCheckInForm::create($this, SelfCheckInForm::class);
    }

    public function selfcheckin($request)
    {
        if ($this->IsOnLocation()) {
            $this->Content = '
                <h1>Self Check-In</h1>
                <p class="message good">' . $this->OnLocationCheckinMessage . '</p>';
            $this->Form = $this->SelfCheckInForm();
        } else {
            $this->Content = '
                <h1>Self Check-In</h1>
                <p class="message warning">' . $this->NotOnLocationCheckinMessage . '</p>';
        }

        //this page will always render without a header/footer -
        //regardless of whether or not it is being accessed from the location
        return $this->RenderWith(['Page_MainOnly', 'SelfCheckInPage']);
    }

    public function confirmselfcheckin($request)
    {
        if (!$this->currentBooking) {
            return $this->httpError(404, 'Not Found');
        }

        $this->Content = $this->RenderWith('Sunnysideup/Bookings/Includes/SelfCheckInConfirmationContent');

        //this page will also always render without a header/footer - regardless of whether or not it is being accessed from the location
        return $this->RenderWith(['Page_MainOnly', 'Page']);
    }

    public function CurrentTour(): ?Tour
    {
        return $this->currentTour;
    }

    public function TourLinksBooking(): string
    {
        return $this->TourLinks(Booking::class)->first();
    }

    public function TourLinks(?string $className = ''): ArrayList
    {
        $modelAdmin = Injector::inst()->get(TourBookingsAdmin::class);
        $models = $modelAdmin->getManagedModels();
        $al = ArrayList::create();
        foreach (array_keys($models) as $key) {
            if ($className) {
                if ($className === $key) {
                    $al->push(Injector::inst()->get($key)->CMSListLink());
                }
            } else {
                $al->push(Injector::inst()->get($key));
            }
        }

        return $al;
    }

    public function TourBookingsAdminLink(): string
    {
        $member = Security::getCurrentUser();
        if ($member && $this->CurrentUserIsTourManager($member)) {
            return $this->AbsoluteLink('calendar');
        }

        return '';
    }

    public function IsCancellation()
    {
        return (bool) $this->isCancellation;
    }

    public function IsOnLocation(): bool
    {
        $hideHeader = (bool) $this->request->getVar('hideheader');
        //if hideheader get var has explicitly been set to false then pretend this is not the location, even it if is
        return $this->locationIP === $_SERVER['REMOTE_ADDR'] || $hideHeader;
    }

    protected function init()
    {
        parent::init();
        $this->locationIP = Config::inst()->get(TourBookingSettings::class, 'tour_location_ip');
        $countries = json_encode(Config::inst()->get(TourBookingForm::class, 'show_city_field_for_countries'));
        $settings = TourBookingSettings::inst();
        $linkWithoutGetVars = explode('?', $this->Link())[0];
        Requirements::customScript(
            '
                if(typeof TourBookingsInPageData === "undefined") {
                    var TourBookingsInPageData = {};
                }
                TourBookingsInPageData.url = "' . trim((string) $linkWithoutGetVars, '/') . '";
                TourBookingsInPageData.maxPerGroup = "' . $settings->MaximumNumberPerGroup . '";
                TourBookingsInPageData.emailContact = "' . $settings->Administrator()->Email . '";
                TourBookingsInPageData.showCityTownForCountries = ' . $countries . ';
            ',
            'TourBookingsInPageData'
        );
        $this->getBookingFromRequestOrIDParam();
    }

    //######################
    // protected functions
    //######################

    protected function getBookingFromRequestOrIDParam(): ?Booking
    {
        $this->currentBooking = null;
        $code = '';
        if ($code = $this->request->postVar('BookingCode')) {
            $code = Convert::raw2sql($code);
        } else {
            $code = Convert::raw2sql($this->request->param('ID'));
        }
        if ($code) {
            $count = Booking::get()->filter(['Code' => $code])->count();
            if ($count > 1) {
                user_error('There are duplicate bookings with the same Boooking Code');
            }
            $this->currentBooking = Booking::get()->filter(['Code' => $code])->last();
        }

        return $this->currentBooking;
    }

    protected function getTourFromRequestOrIDParam(): ?Tour
    {
        $this->currentTour = null;
        $id = (int) $this->request->requestVar('TourID');
        if (!$id) {
            $id = (int) $this->request->param('ID');
        }
        $this->currentTour = Tour::get()->byID($id);

        return $this->currentTour;
    }

    protected function getNumberOfGuestsFromRequestOrIDParam(): ?int
    {
        $this->totalNumberOfGuests = null;
        $guests1 = (int) $this->request->param('OtherID');
        $guests2 = (int) $this->request->postVar('TotalNumberOfGuests');
        if ($guests1 > 0) {
            $this->totalNumberOfGuests = $guests1;
        } elseif ($guests2 > 0) {
            $this->totalNumberOfGuests = $guests2;
        }

        return $this->totalNumberOfGuests;
    }

    /**
     * returns an ArrayData with
     *   PreviousDay: list of tours
     *   RequestedDay: list of tours
     *   NextDay: list of tours.
     *
     * @param int $numberOfPlacesRequested
     *
     * @return ArrayList
     */
    protected function findTours(int $dateTS, ?int $numberOfPlacesRequested = 0)
    {
        $finalArrayList = ArrayList::create();
        $dateMysql = date('Y-m-d', $dateTS);
        $settings = TourBookingSettings::inst();

        $filters = [
            'Date' => $dateMysql,
            'IsClosed' => false,
        ];

        $tours = Tour::get()->filter($filters)->sort(['StartTime' => 'ASC', 'ID' => 'ASC']);

        if (isset($settings->BookingTimeCutOff) && $settings->BookingTimeCutOff) {
            $time  = date("Y-m-d H:i:s", strtotime("-" . (int)$settings->BookingTimeCutOff . " minutes"));
            $tours = $tours->where('TIMESTAMP("Date", "StartTime") > TIMESTAMP(\'' . $time . '\')');
        }

        $myTourID = 0;
        if ($this->currentBooking && $this->currentBooking->exists()) {
            $myTourID = $this->currentBooking->TourID;
        }
        foreach ($tours as $tour) {
            $calculatedNumberOfPlacesRequested = $numberOfPlacesRequested;
            if ($tour->ID === $myTourID) {
                $calculatedNumberOfPlacesRequested = $numberOfPlacesRequested - $this->currentBooking->TotalNumberOfGuests;
            }
            if (0 === $tour->getNumberOfPlacesAvailable()->Value && $calculatedNumberOfPlacesRequested > 0) {
                $availability = 'Full';
                $isAvailable = false;
            } elseif ($tour->getNumberOfPlacesAvailable()->Value >= $calculatedNumberOfPlacesRequested) {
                $availability = 'Available';
                $isAvailable = true;
            } else {
                $availability = 'Unavailable';
                $isAvailable = false;
            }
            if (!isset($finalArray[$tour->ID])) {
                $finalArray[$tour->ID] = ArrayList::create();
            }
            $tour->Availability = $availability;
            $tour->IsAvailable = $isAvailable;
            $finalArrayList->push($tour);
        }

        return $finalArrayList;
    }

    /**
     * Handle payment callback after 3D Secure authentication
     */
    public function paymentcallback($request)
    {
        $status = $request->param('Status');
        $payment = $this->getPaymentFromRequest($request);

        if (!$payment) {
            return $this->httpError(404, 'Payment not found');
        }

        // Complete the payment after 3D Secure authentication
        $service = ServiceFactory::create()->getService($payment, ServiceFactory::INTENT_PURCHASE);
        $response = $service->complete();

        if ($response->isSuccessful()) {
            // Update booking status using centralized method
            $booking = $this->getBookingFromPayment($payment);
            if ($booking) {
                $booking->markPaymentSuccessful(
                    $response->getTransactionReference(),
                    $response->getPaymentIntentReference()
                );
            }

            return $this->redirect($this->Link('paymentsuccess'));
        } else {
            // Update booking status to failed using centralized method
            $booking = $this->getBookingFromPayment($payment);
            if ($booking) {
                $booking->markPaymentFailed(
                    $response->getTransactionReference(),
                    $response->getMessage()
                );
            }

            return $this->redirect($this->Link('paymentfailure'));
        }
    }

    /**
     * Handle Stripe webhooks
     */
    public function paymentwebhook($request)
    {
        $logger = Injector::inst()->get(LoggerInterface::class);

        // Test mode - accessible via browser with ?test=1
        if ($request->getVar('test') === '1') {
            $response = new HTTPResponse(
                'SUCCESS: Webhook routing is working correctly!' . PHP_EOL .
                'Route: payment → Method: paymentwebhook()' . PHP_EOL .
                'URL: ' . $request->getURL() . PHP_EOL .
                'Stripe API Version: 2024-04-10',
                200
            );
            $response->addHeader('Content-Type', 'text/plain');
            return $response;
        }

        // VALIDATION: Check if this is a legitimate Stripe webhook call
        if (!$this->isValidStripeWebhookRequest($request, $logger)) {
            return $this->httpError(400, 'Invalid webhook request');
        }

        try {
            $payload = $request->getBody();
            $sigHeader = $request->getHeader('Stripe-Signature');
            $endpointSecret = Environment::getEnv('STRIPE_WEBHOOK_SECRET');

            // SECURITY: Always require webhook secret
            if (!$endpointSecret) {
                $logger->error('Webhook endpoint called without STRIPE_WEBHOOK_SECRET configured');
                return $this->httpError(400, 'Webhook secret required');
            }

            try {
                $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
            } catch(\UnexpectedValueException $e) {
                $logger->warning('Invalid webhook payload received', ['error' => $e->getMessage()]);
                return $this->httpError(400, 'Invalid payload');
            } catch(\Stripe\Exception\SignatureVerificationException $e) {
                $logger->warning('Invalid webhook signature received', ['error' => $e->getMessage()]);
                return $this->httpError(400, 'Invalid signature');
            }

            // Process webhook with proper error handling
            $result = $this->processWebhookEvent($event);

            if ($result) {
                return new HTTPResponse('OK', 200);
            } else {
                return $this->httpError(400, 'Event processing failed');
            }

        } catch (Exception $e) {
            $logger->critical('Unexpected webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->httpError(500, 'Internal server error');
        }
    }

    /**
     * Process webhook event with replay protection
     */
    protected function processWebhookEvent($event): bool
    {
        // Handle the event
        switch ($event->type) {
            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object;
                return $this->handlePaymentSuccess($paymentIntent, $event->id, $event->created);
            case 'payment_intent.payment_failed':
                $paymentIntent = $event->data->object;
                return $this->handlePaymentFailure($paymentIntent, $event->id, $event->created);
            default:
                // Log unexpected event type but don't fail
                $logger = Injector::inst()->get(LoggerInterface::class);
                $logger->info('Unexpected webhook event type received: ' . $event->type);
                return true;
        }
    }

    /**
     * Handle successful payment via webhook
     */
    protected function handlePaymentSuccess($paymentIntent, string $eventId = '', int $eventTimestamp = 0): bool
    {
        $logger = Injector::inst()->get(LoggerInterface::class);

        // Find booking by payment intent ID
        $booking = Booking::get()
            ->filter('PaymentIntentId', $paymentIntent->id)
            ->first();

        if (!$booking) {
            $logger->warning('Webhook payment success: booking not found', [
                'payment_intent_id' => $paymentIntent->id
            ]);
            return false;
        }

        // REPLAY PROTECTION: Check if this event should be processed
        if ($eventId && $eventTimestamp && !$booking->shouldProcessWebhookEvent($eventId, $eventTimestamp)) {
            $logger->info('Webhook replay detected for payment success', [
                'booking_code' => $booking->Code,
                'event_id' => $eventId
            ]);
            return false;
        }

        // Use centralized payment status method
        $booking->markPaymentSuccessful($paymentIntent->id, $paymentIntent->id);

        // Mark event as processed
        if ($eventId && $eventTimestamp) {
            $booking->markWebhookProcessed($eventId, $eventTimestamp);
        }

        $logger->info('Payment success processed via webhook', [
            'booking_code' => $booking->Code,
            'payment_intent_id' => $paymentIntent->id
        ]);

        return true;
    }

    /**
     * Handle failed payment via webhook
     */
    protected function handlePaymentFailure($paymentIntent, string $eventId = '', int $eventTimestamp = 0): bool
    {
        $logger = Injector::inst()->get(LoggerInterface::class);

        $booking = Booking::get()
            ->filter('PaymentIntentId', $paymentIntent->id)
            ->first();

        if (!$booking) {
            $logger->warning('Webhook payment failure: booking not found', [
                'payment_intent_id' => $paymentIntent->id
            ]);
            return false;
        }

        // REPLAY PROTECTION: Check if this event should be processed
        if ($eventId && $eventTimestamp && !$booking->shouldProcessWebhookEvent($eventId, $eventTimestamp)) {
            $logger->info('Webhook replay detected for payment failure', [
                'booking_code' => $booking->Code,
                'event_id' => $eventId
            ]);
            return false;
        }

        // Use centralized payment status method
        $booking->markPaymentFailed($paymentIntent->id, 'Payment failed via webhook');

        // Mark event as processed
        if ($eventId && $eventTimestamp) {
            $booking->markWebhookProcessed($eventId, $eventTimestamp);
        }

        $logger->warning('Payment failure processed via webhook', [
            'booking_code' => $booking->Code,
            'payment_intent_id' => $paymentIntent->id
        ]);

        return true;
    }

    /**
     * Get payment from request
     */
    protected function getPaymentFromRequest($request)
    {
        $identifier = $request->param('Identifier');
        if (!$identifier) {
            $identifier = $request->getVar('identifier');
        }
        if (!$identifier) {
            return null;
        }
        return Payment::get()
            ->filter('Identifier', $identifier)
            ->filter('Identifier:not', '')
            ->first();
    }

    /**
     * Get booking from payment
     */
    protected function getBookingFromPayment($payment)
    {
        // Find booking that owns this payment
        return Booking::get()
            ->filter('Payments.ID', $payment->ID)
            ->first();
    }

    /**
     * Validate if the request is a legitimate Stripe webhook call
     * 
     * @param HTTPRequest $request
     * @param LoggerInterface $logger
     * @return bool
     */
    protected function isValidStripeWebhookRequest($request, LoggerInterface $logger): bool
    {
        // 1. Check HTTP method - Stripe webhooks are always POST
        if (!$request->isPOST()) {
            $logger->warning('Webhook request rejected: not a POST request', [
                'method' => $request->httpMethod(),
                'ip' => $request->getIP()
            ]);
            return false;
        }

        // 2. Check for Stripe-Signature header
        $stripeSignature = $request->getHeader('Stripe-Signature');
        if (empty($stripeSignature)) {
            $logger->warning('Webhook request rejected: missing Stripe-Signature header', [
                'ip' => $request->getIP(),
                'user_agent' => $request->getHeader('User-Agent')
            ]);
            return false;
        }

        // 3. Check Content-Type header
        $contentType = $request->getHeader('Content-Type');
        if (empty($contentType) || strpos($contentType, 'application/json') === false) {
            $logger->warning('Webhook request rejected: invalid Content-Type', [
                'content_type' => $contentType,
                'ip' => $request->getIP()
            ]);
            return false;
        }

        // 4. Check for payload
        $payload = $request->getBody();
        if (empty($payload)) {
            $logger->warning('Webhook request rejected: empty payload', [
                'ip' => $request->getIP()
            ]);
            return false;
        }

        // 5. Validate JSON structure
        $data = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $logger->warning('Webhook request rejected: invalid JSON payload', [
                'json_error' => json_last_error_msg(),
                'ip' => $request->getIP()
            ]);
            return false;
        }

        // 6. Check for required Stripe webhook fields
        $requiredFields = ['id', 'object', 'created', 'data', 'type'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                $logger->warning('Webhook request rejected: missing required field', [
                    'missing_field' => $field,
                    'ip' => $request->getIP()
                ]);
                return false;
            }
        }

        // 7. Validate object type is 'event'
        if ($data['object'] !== 'event') {
            $logger->warning('Webhook request rejected: object is not event', [
                'object_type' => $data['object'],
                'ip' => $request->getIP()
            ]);
            return false;
        }

        // 8. Check if event type is one we handle
        $supportedEventTypes = [
            'payment_intent.succeeded',
            'payment_intent.payment_failed',
            'payment_intent.requires_action',
            'payment_intent.canceled'
        ];
        
        if (!in_array($data['type'], $supportedEventTypes)) {
            $logger->info('Webhook event type not handled (but valid)', [
                'event_type' => $data['type'],
                'event_id' => $data['id'],
                'ip' => $request->getIP()
            ]);
            // Return true - it's a valid webhook, just not one we process
            return true;
        }

        // 9. Check User-Agent contains 'Stripe'
        $userAgent = $request->getHeader('User-Agent');
        if (empty($userAgent) || strpos($userAgent, 'Stripe') === false) {
            $logger->warning('Webhook request rejected: suspicious User-Agent', [
                'user_agent' => $userAgent,
                'ip' => $request->getIP()
            ]);
            return false;
        }

        $logger->info('Webhook request passed validation', [
            'event_type' => $data['type'],
            'event_id' => $data['id'],
            'ip' => $request->getIP()
        ]);

        return true;
    }

    /**
     * Payment success page
     */
    public function paymentsuccess($request)
    {
        // Handle successful payment page
        return $this->renderWith(['PaymentSuccess', 'Page']);
    }

    /**
     * Payment failure page
     */
    public function paymentfailure($request)
    {
        // Handle failed payment page
        return $this->renderWith(['PaymentFailure', 'Page']);
    }
}
