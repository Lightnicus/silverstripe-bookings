<?php

namespace Sunnysideup\Bookings\Model;

use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\NumericField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use Sunnysideup\Bookings\Cms\TourBookingsAdmin;
use Sunnysideup\Bookings\Cms\TourBookingsConfig;
use SunnySideUp\EmailReminder\Cms\EmailReminderModelAdmin;
use SunnySideUp\EmailReminder\Model\EmailReminderEmailRecord;
use SunnySideUp\EmailReminder\Model\EmailReminderNotificationSchedule;
use Sunnysideup\GoogleCalendarInterface\GoogleCalendarInterface;
use Sunnysideup\PermissionProvider\Api\PermissionProviderFactory;
use Sunnysideup\SanitiseClassName\Sanitiser;

/**
 * Class \Sunnysideup\Bookings\Model\TourBookingSettings
 *
 * @property int $MaximumNumberPerGroup
 * @property int $NumberOfDaysToGenerateToursInAdvance
 * @property string $TourFullMessage
 * @property string $WaitlistInfoMessage
 * @property string $GoogleCalendarVerificationCode
 * @property string $ConfirmationPageContent
 * @property int $BookingTimeCutOff
 * @property string $WaitlistSuccessMessage
 * @property bool $EnablePayments
 * @property string $PaymentGateway
 * @property string $PaymentInfoMessage
 * @property int $AdministratorID
 * @property int $BookingConfirmationEmailID
 * @property int $UpdateConfirmationEmailID
 * @property int $CancellationConfirmationEmailID
 * @property int $WaitlistConfirmationEmailID
 * @property int $TourSpacesAvailableEmailID
 * @method Member Administrator()
 * @method EmailReminderNotificationSchedule BookingConfirmationEmail()
 * @method EmailReminderNotificationSchedule UpdateConfirmationEmail()
 * @method EmailReminderNotificationSchedule CancellationConfirmationEmail()
 * @method EmailReminderNotificationSchedule WaitlistConfirmationEmail()
 * @method EmailReminderNotificationSchedule TourSpacesAvailableEmail()
 */
class TourBookingSettings extends TourBaseClass
{
    private static $group_code = 'tourmanagers';

    private static $manager_email = '';

    private static $tour_location_ip = '';

    private static $monthly_tour_report_email_to = '';

    //######################
    //## Names Section
    //######################

    private static $singular_name = 'Tour Booking Settings';

    private static $plural_name = 'Tour Booking Settings';

    //######################
    //## Model Section
    //######################
    private static $table_name = 'TourBookingSettings';

    private static $db = [
        'MaximumNumberPerGroup' => 'Int',
        'NumberOfDaysToGenerateToursInAdvance' => 'Int',
        'TourFullMessage' => 'Varchar',
        'WaitlistInfoMessage' => 'Varchar(511)',
        'GoogleCalendarVerificationCode' => 'Varchar(255)',
        'ConfirmationPageContent' => 'HTMLText',
        'BookingTimeCutOff' => 'Int(10)',
        'WaitlistSuccessMessage' => 'Text',
        'EnablePayments' => 'Boolean',
        'PaymentGateway' => 'Varchar(50)',
        'PaymentInfoMessage' => 'HTMLText',
    ];

    private static $has_one = [
        'Administrator' => Member::class,
        'BookingConfirmationEmail' => EmailReminderNotificationSchedule::class,
        'UpdateConfirmationEmail' => EmailReminderNotificationSchedule::class,
        'CancellationConfirmationEmail' => EmailReminderNotificationSchedule::class,
        'WaitlistConfirmationEmail' => EmailReminderNotificationSchedule::class,
        'TourSpacesAvailableEmail' => EmailReminderNotificationSchedule::class,
    ];

    //######################
    //## Further DB Field Details
    //######################

    private static $defaults = [
        'MaximumNumberPerGroup' => '10',
        'NumberOfDaysToGenerateToursInAdvance' => 60,
        'EnablePayments' => false, // Disabled by default
        'PaymentGateway' => 'Stripe',
    ];

    private static $default_sort = 'ID ASC';

    private static $required_fields = [
        'AdministratorID',
    ];

    //######################
    //## Field Names and Presentation Section
    //######################

    private static $field_labels = [
        'Administrator' => 'Tour Booking Manager',
        'TourFullMessage' => 'Tour Full Message',
        'MaximumNumberPerGroup' => 'Max Pax per Group',
        'NumberOfDaysToGenerateToursInAdvance' => 'Tour Auto Generation',
    ];

    private static $field_labels_right = [
        'Administrator' => 'Make sure that the administrator has the right email address.',
        'TourFullMessage' => 'Message displayed to a user when a tour is fully booked',
        'MaximumNumberPerGroup' => 'Maximum number of people per group (set to zero to ignore)',
        'NumberOfDaysToGenerateToursInAdvance' => 'The number of days in advance that tours will be auto generated. The auto generator runs at 2AM everyday, if this is set to 0 then no tours will be auto generated.',
    ];

    private static $summary_fields = [
        'Title' => 'Title',
    ];

    private static $email_fields = [
        'BookingConfirmationEmailID',
        'UpdateConfirmationEmailID',
        'CancellationConfirmationEmailID',
        'WaitlistConfirmationEmailID',
        'TourSpacesAvailableEmailID',
    ];

    private static $message_fields = [
        'TourFullMessage',
        'WaitlistInfoMessage',
        'WaitlistSuccessMessage',
        'ConfirmationPageContent',
    ];

    public function i18n_singular_name()
    {
        return _t('TourBookingSettings.SINGULAR_NAME', 'Tour Booking Settings');
    }

    public function i18n_plural_name()
    {
        return _t('TourBookingSettings.PLURAL_NAME', 'Tour Booking Settings');
    }

    public function getTitle()
    {
        return 'Tour Booking Settings';
    }

    public static function inst()
    {
        $obj = DataObject::get_one(TourBookingSettings::class);
        if (null === $obj) {
            $obj = TourBookingSettings::create();
            $obj->write();
        }

        return $obj;
    }

    //######################
    //## can Section
    //######################

    public function canCreate($member = null, $context = [])
    {
        return !(bool) DataObject::get_one(static::class);
    }

    public function canView($member = null, $context = [])
    {
        return Permission::check('ADMIN', 'any', $member);
    }

    public function canEdit($member = null, $context = [])
    {
        return Permission::check('ADMIN', 'any', $member);
    }

    public function canDelete($member = null, $context = [])
    {
        return false;
    }

    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();
        DB::alteration_message("Creating Tour Bookings Manager's Group and User", 'created');
        $email = Config::inst()->get(TourBookingSettings::class, 'manager_email');
        if (!$email) {
            $baseURL = Director::absoluteBaseURL();
            $baseURL = str_replace('https://', '', $baseURL);
            $baseURL = str_replace('http://', '', $baseURL);
            $baseURL = str_replace('www.', '', $baseURL);
            $baseURL = trim((string) $baseURL, '/');
            $email = 'tours@' . $baseURL;
        }

        $group = PermissionProviderFactory::inst()
            ->setEmail($email)
            ->setFirstName('Tour')
            ->setSurname('Manager')
            ->setCode(Config::inst()->get(TourBookingSettings::class, 'group_code'))
            ->setGroupName('Tour Managers')
            ->setPermissionCode('CMS_ACCESS_TOUR_ADMIN')
            ->setRoleTitle('Tour Manager Privileges')
            ->setForcePasswordReset(false)
            ->setPermissionArray([
                'CMS_ACCESS_' . TourBookingsAdmin::class,
                'CMS_ACCESS_' . TourBookingsConfig::class,
                'CMS_ACCESS_' . EmailReminderModelAdmin::class,
                EmailReminderModelAdmin::PERMISSION_PROVIDER_CODE,
            ])            
            ->CreateGroupAndMember();
        $member = $group->Members()->last();

        if (0 === TourBookingSettings::get()->count()) {
            DB::alteration_message('Creating Tour Bookings Settings', 'created');
            $obj = TourBookingSettings::create();
            if (!$obj->AdministratorID) {
                if ($member) {
                    $obj->AdministratorID = $member->ID;
                }
            }
            $obj->write();
        }
    }

    public function createNewGoogleCalendarAccessToken()
    {
        if ($this->GoogleCalendarVerificationCode) {
            $calendar = new GoogleCalendarInterface();
            $calendar->config($this->GoogleCalendarVerificationCode);
        }
    }

    //######################
    //## Import / Export Section
    //######################

    //######################
    //## CMS Edit Section
    //######################

    public function CMSEditLink()
    {
        $controller = \Singleton(TourBookingsConfig::class);

        return $controller->Link() . '/' . Sanitiser::sanitise($this->ClassName) . '/EditForm/field/' . Sanitiser::sanitise($this->ClassName) . '/item/' . $this->ID . '/edit';
    }

    public function CMSAddLink()
    {
        $controller = \Singleton(TourBookingsConfig::class);

        return $controller->Link() . Sanitiser::sanitise($this->ClassName) . '/EditForm/field/' . Sanitiser::sanitise($this->ClassName) . '/item/new';
    }

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        //do first??
        $rightFieldDescriptions = $this->Config()->get('field_labels_right');
        foreach ($rightFieldDescriptions as $field => $desc) {
            $formField = $fields->DataFieldByName($field);
            if (null === $formField) {
                $formField = $fields->DataFieldByName($field . 'ID');
            }
            if (null !== $formField) {
                $formField->setDescription($desc);
            }
        }
        $group = Group::get()
            ->filter(
                [
                    'code' => [
                        Config::inst()->get(TourBookingSettings::class, 'group_code'),
                        'ADMIN',
                    ],
                ]
            )->first();
        if (null !== $group) {
            $members = $group->Members();
            $fields->replaceField(
                'AdministratorID',
                DropdownField::create(
                    'AdministratorID',
                    'Administrator',
                    $members->map()->toArray()
                )
            );
        }

        $fields->addFieldsToTab("Root.Main", [
            NumericField::create(
                'BookingTimeCutOff',
                'Booking Time Cut-off'
            )
                ->setDescription('The period to disallow bookings after a tour has started, in minutes.')
        ]);

        $fields->replaceField(
            'WaitlistInfoMessage',
            TextareaField::create(
                'WaitlistInfoMessage',
                'Waitlist Info Message'
            )->setDescription(
                'Message displayed at the top of the "Join Waitlist" form to provide info about the waitlisting process'
            )
        );

        $fields->replaceField(
            'WaitlistSuccessMessage',
            TextareaField::create(
                'WaitlistSuccessMessage',
                'Waitlist Success Message'
            )->setDescription(
                'Message displayed after the customer joining the Waitlist successfully.'
                    . '<br/>Use [first_name] for displaying customer\'s first name. E.g. THANKS [first_name]! YOU\'RE WAITLISTED FOR THE FOLLOWING TOURS:'
            )
        );

        if (class_exists(GoogleCalendarInterface::class) && !Director::isDev()) {
            $calendar = new GoogleCalendarInterface();
            $calendarVerificationField = $fields->dataFieldByName('GoogleCalendarVerificationCode');
            if (empty($calendar->config())) {
                $calendarVerificationField->setDescription('Enter a Verfication Code to intergrate with a Google Calenadar');
            } else {
                $calendarVerificationField->setDescription('Enter a new Verfication Code to change the Google Account associated with the tour bookings system');
            }
            try {
                $link = $calendar->getAuthLink();
            } catch (\Exception $e) {
                $link = 'Error authentication with Google' . print_r($e, 1);
            }

            $fields->addFieldsToTab(
                'Root.GoogleCalendar',
                [
                    $fields->dataFieldByName('GoogleCalendarVerificationCode'),
                    LiteralField::create(
                        'GoogleCalendarVerificationURL',
                        '<div id="GoogleCalendarVerificationURL_Holder" class="field text">
                            <label class="left" for="GoogleCalendarVerificationURL"></label>
                            <div class="middleColumn">
                                ' . $link . '
                            </div>
                        </div>',
                    )
                ]
            );
        } else {
            $fields->removeByName('GoogleCalendarVerificationCode');
        }

        $emailFields = $this->Config()->get('email_fields');
        foreach ($emailFields as $field) {
            $formField = $fields->DataFieldByName($field);
            $fields->addFieldsToTab(
                'Root.Emails',
                $formField
            );
            $fieldName = $field;
            if ($this->{$fieldName}) {
                $emailNotifier = EmailReminderNotificationSchedule::get()->byID($this->{$field});
                if (null !== $emailNotifier) {
                    $cmsLink = $emailNotifier->CMSEditLink();
                    if ($cmsLink) {
                        $formField->setDescription(
                            DBField::create_field(
                                'HTMLText',
                                '<a href="' . $cmsLink . '" target="_blank">Edit the content of this email</a>? '
                            )
                        );
                    }
                }
            }
        }

        $messageFields = $this->Config()->get('message_fields');
        foreach ($messageFields as $field) {
            $formField = $fields->DataFieldByName($field);
            if ($formField instanceof HTMLEditorField) {
                $formField->setRows(10);
            }
            $fields->addFieldsToTab(
                'Root.Messages',
                $formField
            );
        }

        // Add payment settings tab
        $fields->addFieldsToTab(
            'Root.Payments',
            [
                CheckboxField::create('EnablePayments', 'Enable Online Payments')
                    ->setDescription('Enable Stripe payment processing for bookings'),
                DropdownField::create('PaymentGateway', 'Payment Gateway', [
                    'Stripe' => 'Stripe',
                ]),
                HTMLEditorField::create('PaymentInfoMessage', 'Payment Info Message')
                    ->setDescription('Message displayed to the customer before they are redirected to the payment page'),
                LiteralField::create(
                    'StripeKeysInfo',
                    '<div class="field text">
                        <label class="left">Stripe Environment Variables</label>
                        <div class="middleColumn">
                            <p>All Stripe configuration is handled via environment variables:</p>
                            <ul style="margin-left: 20px; margin-bottom: 10px;">
                                <li><strong>STRIPE_SECRET_KEY:</strong> ' . (Environment::getEnv('STRIPE_SECRET_KEY') ? '<span style="color: green;">✓ Configured</span>' : '<span style="color: red;">✗ Not configured</span>') . ' <em>(Required)</em></li>
                                <li><strong>STRIPE_PUBLISHABLE_KEY:</strong> ' . (Environment::getEnv('STRIPE_PUBLISHABLE_KEY') ? '<span style="color: green;">✓ Configured</span>' : '<span style="color: red;">✗ Not configured</span>') . ' <em>(Required)</em></li>
                                <li><strong>STRIPE_WEBHOOK_SECRET:</strong> ' . (Environment::getEnv('STRIPE_WEBHOOK_SECRET') ? '<span style="color: green;">✓ Configured</span>' : '<span style="color: orange;">⚠ Optional</span>') . ' <em>(Optional - for enhanced security)</em></li>
                            </ul>
                            <p><small>STRIPE_SECRET_KEY and STRIPE_PUBLISHABLE_KEY are required. STRIPE_WEBHOOK_SECRET is optional but recommended for production.</small></p>
                        </div>
                    </div>'
                )
            ]
        );

        return $fields;
    }

    //######################
    //## write Section
    //######################

    protected function onBeforeWrite()
    {
        parent::onBeforeWrite();
        if (class_exists(GoogleCalendarInterface::class) && $this->owner->isChanged('GoogleCalendarVerificationCode')) {
            $this->createNewGoogleCalendarAccessToken();
        }
    }

    protected function onAfterWrite()
    {
        parent::onAfterWrite();
        //...
    }
}
