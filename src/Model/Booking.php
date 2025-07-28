<?php

namespace Sunnysideup\Bookings\Model;

use Dynamic\CountryDropdownField\Fields\CountryDropdownField;
use SilverStripe\Control\Director;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\EmailField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\RequiredFields;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use Sunnysideup\Bookings\Forms\Fields\TourDateFilterField;
use Sunnysideup\Bookings\Pages\TourBookingPage;
use Sunnysideup\Bookings\Search\TourDateFilter;
use Sunnysideup\DataobjectSorter\Api\DataObjectOneFieldAddEditAllLink;
use SunnySideUp\EmailReminder\Model\EmailReminderEmailRecord;
use SunnySideUp\EmailReminder\Model\EmailReminderNotificationSchedule;

/**
 * Class \Sunnysideup\Bookings\Model\Booking
 *
 * @property string $Code
 * @property string $Date
 * @property int $TotalNumberOfGuests
 * @property string $InitiatingFirstName
 * @property string $InitiatingSurname
 * @property string $InitiatingEmail
 * @property string $CountryOfOrigin
 * @property string $CityTown
 * @property string $PrimaryPhone
 * @property string $SecondaryPhone
 * @property int $NumberOfChildren
 * @property bool $SpecialAssistanceRequired
 * @property string $SpecialAssistanceRequiredInfo
 * @property bool $HasArrived
 * @property bool $Cancelled
 * @property bool $TotalGuestsAdminOverride
 * @property string $ReferralText
 * @property int $BookingMemberID
 * @property int $TourID
 * @method Member BookingMember()
 * @method Tour Tour()
 * @method ManyManyList|ReferralOption[] ReferralOptions()
 * @method ManyManyList|TicketType[] TicketTypes()
 */
class Booking extends TourBaseClass
{
    //######################
    //## Names Section
    //######################

    private static $singular_name = 'Booking';

    private static $plural_name = 'Bookings';

    //######################
    //## Model Section
    //######################

    private static $table_name = 'Booking';

    private static $db = [
        'Code' => 'Varchar(9)',
        'Date' => 'Date',
        'TotalNumberOfGuests' => 'Int',
        'InitiatingFirstName' => 'Varchar',
        'InitiatingSurname' => 'Varchar',
        'InitiatingEmail' => 'Varchar',
        'CountryOfOrigin' => 'Varchar(2)',
        'CityTown' => 'Varchar',
        'PrimaryPhone' => 'PhoneField',
        'SecondaryPhone' => 'PhoneField',
        'NumberOfChildren' => 'Int',
        'SpecialAssistanceRequired' => 'Boolean',
        'SpecialAssistanceRequiredInfo' => 'Varchar',
        'HasArrived' => 'Boolean',
        'Cancelled' => 'Boolean',
        'TotalGuestsAdminOverride' => 'Boolean',
        'ReferralText' => 'Varchar',
    ];

    private static $has_one = [
        'BookingMember' => Member::class,
        'Tour' => Tour::class,
    ];

    private static $many_many = [
        'ReferralOptions' => ReferralOption::class,
        'TicketTypes' => TicketType::class,
    ];

    //######################
    //## Further DB Field Details
    //######################

    private static $indexes = [
        'Code' => true,
    ];

    private static $default_sort = 'ID DESC';

    private static $required_fields = [
        'TourID',
        'PrimaryPhone',
        'TotalNumberOfGuests',
        'InitiatingFirstName',
        'InitiatingEmail',
    ];

    private static $searchable_fields = [
        'Tour.Date' => [
            'field' => TourDateFilterField::class,
            'filter' => TourDateFilter::class,
            'title' => 'Tour Date',
        ],
        'Code' => 'PartialMatchFilter',
        'InitiatingFirstName' => 'PartialMatchFilter',
        'InitiatingSurname' => 'PartialMatchFilter',
        'InitiatingEmail' => 'PartialMatchFilter',
        'TotalNumberOfGuests' => 'ExactMatchFilter',
        'PrimaryPhone' => 'PartialMatchFilter',
        'SecondaryPhone' => 'PartialMatchFilter',
        'HasArrived' => 'ExactMatchFilter',
        'Cancelled' => 'ExactMatchFilter',
    ];

    //######################
    //## Field Names and Presentation Section
    //######################

    private static $field_labels = [
        'Code' => 'Booking Reference',
        'InitiatingFirstName' => 'First Name',
        'InitiatingSurname' => 'Surname',
        'InitiatingEmail' => 'Email',
        'TotalNumberOfGuests' => 'Number of People',
        'BookingMember' => 'Contact',
        'HasArrived' => 'Have Arrived',
        'NumberOfChildren' => 'Number of Children attending the tour',
        'PrimaryPhone' => 'Mobile Phone',
        'SecondaryPhone' => 'Secondary Contact Phone',
        'CountryOfOrigin' => 'What country are your from?',
        'CityTown' => 'City or Town',
        'NumberOfAdults' => 'Adults',
        'SpecialAssistanceRequired' => 'Special Assistance',
        'SpecialAssistanceRequiredInfo' => 'Please let us know how we can help?',
    ];

    private static $field_labels_right = [
        'BookingMember' => 'Person making the booking',
        'PrimaryPhone' => "If you don't have a mobile number, please provide a landline number",
        'SecondaryPhone' => 'Enter as +64 5 555 2222',
        'CountryOfOrigin' => 'In what country do most of the people in this group live?',
        'TotalNumberOfGuests' => 'Including children',
    ];

    private static $read_only_fields = [
        'Code',
        'Date',
        'InitiatingSurname',
        'InitiatingFirstName',
        'InitiatingEmail',
    ];

    private static $summary_fields = [
        'Cancelled.NiceAndColourfullInvertedColours' => 'Cancelled',
        'Tour.Date.Short' => 'Date',
        'Tour.StartTime.Short' => 'Time',
        'Created.Short' => 'Created',
        'LastEdited.Ago' => 'Edited',
        'Code' => 'Reference',
        'TotalNumberOfGuests' => 'Guests',
        'InitiatingEmail' => 'Email',
        'PrimaryPhone' => 'Phone 1',
        'SecondaryPhone' => 'Phone 2',
        'CityTown ' => 'City',
        'CountryOfOrigin ' => 'Country',
    ];

    private static $casting = [
        'Title' => 'Varchar',
        'NumberOfAdults' => 'Int',
        'BookingReference' => 'Varchar',
        'ContactSummary' => 'Varchar',
    ];

    public function i18n_singular_name()
    {
        return _t('Booking.SINGULAR_NAME', 'Booking');
    }

    public function i18n_plural_name()
    {
        return _t('Booking.PLURAL_NAME', 'Bookings');
    }

    public function getTitle()
    {
        $v =
            'Booking by ' . $this->BookingMember()->getTitle() .
            ' for ' . $this->getNumberOfAdults()->Nice() . ' adults,' .
            ' and ' . $this->NumberOfChildren . ' children, ' .
            ' on ' . $this->Tour()->Date .
            ' at ' . $this->Tour()->StartTime .
            ' by ' . $this->InitiatingEmail;

        return DBField::create_field('Varchar', $v);
    }

    public function NumberOfAdults()
    {
        return $this->getNumberOfAdults();
    }

    public function getNumberOfAdults()
    {
        $v = $this->TotalNumberOfGuests - $this->NumberOfChildren;

        return DBField::create_field('Int', $v);
    }

    public function BookingReference()
    {
        return $this->getBookingReference();
    }

    public function getBookingReference()
    {
        $v = strtoupper(substr((string) $this->Code, 0, 5));

        return DBField::create_field('Varchar', $v);
    }

    public function ContactSummary()
    {
        return $this->getContactSummary();
    }

    public function getContactSummary()
    {
        $v = [
            $this->InitiatingFirstName . ' ' . $this->InitiatingSurname . ' ',
            $this->InitiatingEmail,
            $this->PrimaryPhone,
            $this->SecondaryPhone,
            $this->CityTown,
            $this->CountryOfOrigin,
        ];
        $v = array_filter($v);

        return DBField::create_field('Varchar', implode(' / ', $v));
    }

    //######################
    //## can Section
    //######################

    public function canEdit($member = null, $context = [])
    {
        if ($this->HasArrived) {
            return false;
        }

        return parent::canEdit($member);
    }

    public function canDelete($member = null, $context = [])
    {
        if ($this->HasArrived) {
            return false;
        }

        return parent::canEdit($member);
    }

    //######################
    //## write Section
    //######################

    public function validate()
    {
        $result = parent::validate();
        //check for other bookings with same email ....
        if ($this->TourID) {
            if ((bool) $this->Cancelled !== true) {
                $errorCount = Booking::get()
                    ->filter(['InitiatingEmail' => $this->InitiatingEmail,  'TourID' => $this->TourID])
                    ->exclude(['ID' => $this->ID])
                    ->count();
                if (0 !== $errorCount) {
                    $result->addError(
                        'Another booking for this tour with the same email already exists.
                            You can only make one booking per tour per email address.',
                        'UNIQUE_' . $this->ClassName . '_InitiatingEmail'
                    );
                }

                if ($this->PrimaryPhone && (bool) $this->Cancelled !== true) {
                    $errorCount = Booking::get()
                        ->filter(['PrimaryPhone' => $this->PrimaryPhone,  'TourID' => $this->TourID])
                        ->exclude(['ID' => $this->ID])
                        ->count();
                    if (0 !== $errorCount) {
                        $result->addError(
                            'Another booking for this tour with the same mobile phone already exists.
                                    You can only make one booking per tour per mobile phone number.',
                            'UNIQUE_' . $this->ClassName . 'PrimaryPhone'
                        );
                    }
                }
                $tour = Tour::get()->byID($this->TourID);
                if (null !== $tour && (bool) $this->Cancelled !== true) {
                    $availableRaw = $tour->getNumberOfPlacesAvailable()->RAW();
                    if ($this->exists()) {
                        //we have to get the booking from the DB again because that value for $this->TotalNumberOfGuests has already changed
                        $beforeUpdate = Booking::get()->byID($this->ID);
                        $placesAvailable = $availableRaw + $beforeUpdate->TotalNumberOfGuests;
                        //one extra check to make sure placesAvailable is never greater the how many places available for the tour
                        if ($placesAvailable > $tour->TotalSpacesAtStart) {
                            $placesAvailable = $tour->TotalSpacesAtStart;
                        }
                    } else {
                        $placesAvailable = $availableRaw;
                    }
                    //admins can override the following validation
                    $adminOverrideNotSet = !(bool) $this->TotalGuestsAdminOverride;
                    if ($this->TotalNumberOfGuests > $placesAvailable && $adminOverrideNotSet) {
                        $result->addError(
                            'Sorry, there are not enough places available for your booking.
                                    Your booking is for ' . $this->TotalNumberOfGuests . ' and the places still available is: ' . ($placesAvailable > 0 ? $placesAvailable : 0),
                            'UNIQUE_' . $this->ClassName . '_NumberOfPlacesAvailable'
                        );
                    }
                }
            }
            if ((int) $this->TotalNumberOfGuests < 1) {
                $result->addError(
                    'You need to have at least one person attending to make a booking.',
                    'UNIQUE_' . $this->ClassName . '_TotalNumberOfGuests'
                );
            }
            if ((int) $this->TotalNumberOfGuests < ((int) $this->NumberOfChildren + 1)) {
                $result->addError(
                    'You need to have at least one adult attending. It appears you only have children listed for this booking.',
                    'UNIQUE_' . $this->ClassName . '_NumberOfChildren'
                );
            }
        }
        return $result;
    }

    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();
        //...
    }

    //######################
    //## Import / Export Section
    //######################

    //######################
    //## CMS Edit Section
    //######################

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        $fields->removeByName('TotalGuestsAdminOverride');

        $fields->insertBefore(
            'InitiatingFirstName',
            CheckboxField::create(
                'TotalGuestsAdminOverride',
                'Total Guests Override (Admin Only)'
            )->setDescription('If this is checked, it will allow you create a booking with more guests than spaces available on the tour. Useful when you need to add bookings for large groups.'),
        );

        if ($this->TourID) {
            $tour = $this->Tour();
            $fields->replaceField(
                'TourID',
                LiteralField::create(
                    'TourDetails',
                    '<div class="field readonly">
                        <label class="left">Tour</label>
                        <div class="middleColumn">
                            <a href="' . $tour->CMSEditLink() . '">' . $tour->getTitle() . '</a>
                        </div>
                    </div>'
                )
            );
        }

        $fields->replaceField(
            'Country Of Origin',
            CountryDropdownField::create(
                'CountryOfOrigin',
                'CountryOfOrigin'
            )
        );

        $fields->replaceField(
            'Code',
            ReadonlyField::create(
                'Code',
                'Code'
            )
        );

        if ($this->BookingMemberID) {
            $fields->replaceField(
                'BookingMemberID',
                ReadonlyField::create(
                    'BookingMemberInfo',
                    'Person making the booking',
                    $this->BookingMember()->getTitle() . ' - ' . $this->BookingMember()->Email
                )
            );

            $readonlyfields = Config::inst()->get(Booking::class, 'read_only_fields');
            foreach ($readonlyfields as $replaceField) {
                $fields->replaceField(
                    $replaceField,
                    $fields->dataFieldByName($replaceField)->performReadonlyTransformation()->setTitle('Original        ' . str_replace('Initiating', '', $replaceField))
                );
            }
        } else {
            $fields->removeByName('BookingMemberID');
            $fields->removeByName('Date');
            $fields->removeByName('TourID');
            $today = date('Y-m-d');
            $tours = Tour::get()->filter(
                ['Date:GreaterThanOrEqual' => $today]
            )->map()->toArray();
            $fields->insertBefore(
                'TotalNumberOfGuests',
                DropdownField::create('TourID', 'Tour', $tours),
            );
        }

        $fields->removeByName('ReferralText');
        $fields->removeByName('ReferralOptions');

        $fields->addFieldsToTab(
            'Root.ReferralInfo',
            [
                HeaderField::create(
                    'ReferralInfoHeading',
                    'How did the booking contact hear about this tour?',
                    2
                ),
                GridField::create(
                    'ReferralOptions',
                    'Options Selected',
                    $this->ReferralOptions(),
                    GridFieldConfig_RecordViewer::create()
                ),
                ReadonlyField::create(
                    'ReferralText',
                    'More Details'
                )->setDescription('There will only be data here if the user provides more details when selecting the "other" option.'),
            ]
        );

        $emailRecords = EmailReminderEmailRecord::get()->filter(['ExternalRecordID' => $this->ID]);

        $fields->addFieldsToTab(
            'Root.Messages',
            [
                ReadonlyField::create(
                    'Created',
                    'Booking Made'
                ),
                GridField::create(
                    'Email',
                    'Emails Sent',
                    $emailRecords,
                    GridFieldConfig_RecordViewer::create()
                ),
            ]
        );

        $this->addUsefulLinkToFields($fields, 'Add New Booking', $this->AddLink());
        if ($this->Code) {
            $this->addUsefulLinkToFields($fields, 'Confirm Booking', $this->ConfirmLink());
            $this->addUsefulLinkToFields($fields, 'Edit Booking', $this->ConfirmLink());
            $this->addUsefulLinkToFields($fields, 'Cancel Booking', $this->CancelLink());
        }

        DataObjectOneFieldAddEditAllLink::add_edit_links_to_checkboxes(self::class, $fields);

        return $fields;
    }

    public function getFrontEndFields($params = null)
    {
        $fields = parent::getFrontEndFields($params);
        $labels = Config::inst()->get(Booking::class, 'field_labels');
        $fieldLabelsRight = Config::inst()->get(Booking::class, 'field_labels_right');
        $fields->removeByName('Code');
        $fields->removeByName('Date');
        $fields->removeByName('HasArrived');
        $fields->removeByName('Cancelled');
        $fields->removeByName('BookingMemberID');
        $fields->removeByName('TotalNumberOfGuests');
        $fields->removeByName('SecondaryPhone');
        $fields->removeByName('TotalGuestsAdminOverride');
        $fields->removeByName('ReferralText');

        $fields->replaceField(
            'PrimaryPhone',
            TextField::create(
                'PrimaryPhone',
                $labels['PrimaryPhone']
            )->setDescription($fieldLabelsRight['PrimaryPhone'])
        );

        $fields->replaceField(
            'InitiatingEmail',
            EmailField::create(
                'InitiatingEmail',
                $labels['InitiatingEmail']
            )
        );

        $fields->replaceField(
            'NumberOfChildren',
            NumericField::create(
                'NumberOfChildren',
                $labels['NumberOfChildren']
            )->setScale(0)
        );

        $fields->replaceField(
            'CountryOfOrigin',
            CountryDropdownField::create(
                'CountryOfOrigin',
                $labels['CountryOfOrigin'],
            )
        );

        $fields->replaceField(
            'CityTown',
            TextField::create(
                'CityTown',
                $labels['CityTown']
            )
        );

        $fields->replaceField(
            'TourID',
            HiddenField::create(
                'TourID',
                'TourID'
            )
        );

        return $fields;
    }

    /**
     * Validation for the front end.
     *
     * @return RequiredFields
     */
    public function getFrontEndValidator()
    {
        $fields = Config::inst()->get(Booking::class, 'required_fields');

        return RequiredFields::create($fields);
    }

    /**
     * This function is used to exclude cancelled bookings from reminder and follow up emails.
     *
     * @param EmailReminderNotificationSchedule $reminder
     * @param DataList                          $records
     */
    public function EmailReminderExclude($reminder, $records): bool
    {
        return (bool) $this->Cancelled;
    }

    //######################
    //## Links
    //######################

    public function AddLink($absolute = false): string
    {
        return $this->createLink('signup');
    }

    public function ConfirmLink($absolute = false): string
    {
        return $this->createLink('confirmsignup');
    }

    public function EditLink($absolute = false): string
    {
        return $this->createLink('update');
    }

    public function CancelLink($absolute = false): string
    {
        return $this->createLink('cancel');
    }

    protected function createLink(?string $action = ''): string
    {
        if ($this->Code) {
            $code = substr((string) $this->Code, 0, 9);
            $link = TourBookingPage::find_link($action . '/' . $code);
        } else {
            $link = 'error/in/' . $action . '/for/' . $this->ID . '/';
        }

        return Director::absoluteURL($link);
    }

    protected function onBeforeWrite()
    {
        parent::onBeforeWrite();
        if (!$this->Code) {
            $this->Code = hash('md5', uniqid());
        }
        $this->Date = $this->Tour()->Date;
    }

    protected function onAfterWrite()
    {
        parent::onAfterWrite();

        //create member ...
        if (!$this->BookingMemberID && $this->InitiatingEmail) {
            $member = Member::get()->filter(['Email' => $this->InitiatingEmail])->last();
            if (null === $member) {
                $member = Member::create(
                    [
                        'Email' => $this->InitiatingEmail,
                        'FirstName' => $this->InitiatingFirstName,
                        'Surname' => $this->InitiatingSurname,
                    ]
                );
                $member->write();
            }
            $this->BookingMemberID = $member->ID;
            if (0 !== $this->BookingMemberID) {
                $this->write();
            }
        }

        //always update the tour after a booking has been updated/added
        //this ensures that data for the tour is always up to date and that it will be synched with the google calendar
        $this->Tour()->write();
    }

    protected function onAfterDelete()
    {
        parent::onAfterDelete();
        //always update the tour after a booking has been deleted
        //this ensures that data for the tour is always up to date and that it will be synched with the google calendar
        $this->Tour()->write();
    }

    protected function CurrentMemberIsOwner(): bool
    {
        return (int) Security::getCurrentUser()?->ID === (int) $this->BookingMemberID;
    }
}
