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
use SilverStripe\Forms\GridField\GridFieldDataColumns;
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
 * @property string $PaymentStatus
 * @property string $PaymentGateway
 * @property string $PaymentReference
 * @property string $PaymentDate
 * @property string $PaymentIntentId
 * @property int $LastWebhookTimestamp
 * @property string $LastWebhookEventId
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
 * @property bool $PeanutAllergyConfirmation
 * @property bool $MarketingEmailOptOut
 * @property int $BookingMemberID
 * @property int $TourID
 * @method Member BookingMember()
 * @method Tour Tour()
 * @method DataList|Payment[] Payments()
 * @method ManyManyList|ReferralOption[] ReferralOptions()
 * @method ManyManyList|TicketType[] TicketTypes()
 * @mixin BookingPaymentExtension
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
        'PeanutAllergyConfirmation' => 'Boolean',
        'MarketingEmailOptOut' => 'Boolean',
    ];

    private static $has_one = [
        'BookingMember' => Member::class,
        'Tour' => Tour::class,
    ];

    private static $many_many = [
        'ReferralOptions' => ReferralOption::class,
        'TicketTypes' => TicketType::class,
    ];

    private static $many_many_extraFields = [
        'TicketTypes' => [
            'Quantity' => 'Int',
        ],
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
        'PeanutAllergyConfirmation',
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

        'PrimaryPhone' => 'Mobile Phone',
        'SecondaryPhone' => 'Secondary Contact Phone',
        'CountryOfOrigin' => 'What country are your from?',
        'CityTown' => 'City or Town',
        'NumberOfAdults' => 'Adults',
        'SpecialAssistanceRequired' => 'Special Assistance',
        'SpecialAssistanceRequiredInfo' => 'Please let us know how we can help?',
        'PeanutAllergyConfirmation' => 'Peanut Allergy Confirmation',
        'MarketingEmailOptOut' => 'Marketing Email Opt Out',
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
        'getTotalSpots' => 'Total Spots',
        'getNumberOfAdults' => 'Adults',
        'getNumberOfKids' => 'Kids',
        'getTicketTypesSummary' => 'Ticket Types',
        'InitiatingEmail' => 'Email',
        'PrimaryPhone' => 'Phone 1',
        'SecondaryPhone' => 'Phone 2',
        'CityTown ' => 'City',
        'CountryOfOrigin ' => 'Country',
    ];

    private static $casting = [
        'Title' => 'Varchar',
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
        // Use ticket types if available, otherwise fall back to old system
        if ($this->TicketTypes()->count() > 0) {
            $totalAdults = $this->getTotalAdultsFromTicketTypes();
            $totalKids = $this->getTotalKidsFromTicketTypes();
            $totalSpots = $this->getTotalSpotsFromTicketTypes();
            
            $v = 'Booking by ' . $this->BookingMember()->getTitle() .
                ' for ' . $totalAdults . ' adults,' .
                ' and ' . $totalKids . ' children, ' .
                ' on ' . $this->Tour()->Date .
                ' at ' . $this->Tour()->StartTime .
                ' by ' . $this->InitiatingEmail;
        } else {
            // Fallback to old system
            $v = 'Booking by ' . $this->BookingMember()->getTitle() .
                ' for ' . $this->getNumberOfAdults()->Nice() . ' adults,' .
                ' and ' . $this->NumberOfChildren . ' children, ' .
                ' on ' . $this->Tour()->Date .
                ' at ' . $this->Tour()->StartTime .
                ' by ' . $this->InitiatingEmail;
        }

        return DBField::create_field('Varchar', $v);
    }

    public function NumberOfAdults()
    {
        return $this->getNumberOfAdults();
    }

    public function getNumberOfAdults()
    {
        // Use ticket types if available, otherwise fall back to old calculation
        if ($this->TicketTypes()->count() > 0) {
            $v = $this->getTotalAdultsFromTicketTypes();
        } else {
            // Extract raw values for arithmetic operations
            $totalGuests = $this->TotalNumberOfGuests;
            if ($totalGuests instanceof \SilverStripe\ORM\FieldType\DBField) {
                $totalGuests = $totalGuests->RAW();
            }
            $numberOfChildren = $this->NumberOfChildren;
            if ($numberOfChildren instanceof \SilverStripe\ORM\FieldType\DBField) {
                $numberOfChildren = $numberOfChildren->RAW();
            }
            $v = (int) $totalGuests - (int) $numberOfChildren;
        }

        return DBField::create_field('Int', $v);
    }

    /**
     * Get total number of kids (from ticket types or fallback)
     */
    public function getNumberOfKids()
    {
        // Use ticket types if available, otherwise fall back to old calculation
        if ($this->TicketTypes()->count() > 0) {
            $v = $this->getTotalKidsFromTicketTypes();
        } else {
            $v = $this->NumberOfChildren;
            if ($v instanceof \SilverStripe\ORM\FieldType\DBField) {
                $v = $v->RAW();
            }
            $v = (int) $v;
        }

        return DBField::create_field('Int', $v);
    }

    /**
     * Get total number of spots (from ticket types or fallback)
     */
    public function getTotalSpots()
    {
        // Use ticket types if available, otherwise fall back to old calculation
        if ($this->TicketTypes()->count() > 0) {
            $v = $this->getTotalSpotsFromTicketTypes();
        } else {
            $v = $this->TotalNumberOfGuests;
            if ($v instanceof \SilverStripe\ORM\FieldType\DBField) {
                $v = $v->RAW();
            }
            $v = (int) $v;
        }

        return DBField::create_field('Int', $v);
    }

    /**
     * Get the total number of guests (calculated from ticket types or database field)
     */
    public function getTotalNumberOfGuests()
    {
        // Use ticket types if available, otherwise fall back to database field
        if ($this->TicketTypes()->count() > 0) {
            $v = $this->getTotalSpotsFromTicketTypes();
        } else {
            $v = $this->dbObject('TotalNumberOfGuests')->RAW();
        }

        // Ensure we pass a raw integer, not a DBField object
        if ($v instanceof \SilverStripe\ORM\FieldType\DBField) {
            $v = $v->RAW();
        }
        $v = (int) $v;

        return DBField::create_field('Int', $v);
    }

    /**
     * Get the number of children (calculated from ticket types or database field)
     */
    public function getNumberOfChildren()
    {
        // Use ticket types if available, otherwise fall back to database field
        if ($this->TicketTypes()->count() > 0) {
            $v = $this->getTotalKidsFromTicketTypes();
        } else {
            $v = $this->dbObject('NumberOfChildren')->RAW();
        }

        // Ensure we pass a raw integer, not a DBField object
        if ($v instanceof \SilverStripe\ORM\FieldType\DBField) {
            $v = $v->RAW();
        }
        $v = (int) $v;

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

    public function validate(array $ticketTypeData = null)
    {
        // Only call parent::validate() on saved objects
        if ($this->exists()) {
            $result = parent::validate();
        } else {
            $result = \SilverStripe\ORM\ValidationResult::create();
        }
        
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
                
                // Only perform guest validations and tour availability checks on saved objects
                if ($this->exists()) {
                    // Validate peanut allergy confirmation
                    if (!(bool) $this->PeanutAllergyConfirmation) {
                        $result->addError(
                            'You must confirm that no one in your group is allergic to peanuts.',
                            'UNIQUE_' . $this->ClassName . '_PeanutAllergyConfirmation'
                        );
                    }
                    
                    // Validate referral options
                    if ($this->ReferralOptions()->count() === 0) {
                        $result->addError(
                            'Please select how you heard about our tours.',
                            'UNIQUE_' . $this->ClassName . '_ReferralOptions'
                        );
                    }
                    
                    $tour = Tour::get()->byID($this->TourID);
                    if (null !== $tour && (bool) $this->Cancelled !== true) {
                        $availableRaw = $tour->getNumberOfPlacesAvailable()->RAW();
                        //we have to get the booking from the DB again because that value for $this->TotalNumberOfGuests has already changed
                        $beforeUpdate = Booking::get()->byID($this->ID);
                        $totalGuests = $beforeUpdate->TotalNumberOfGuests;
                        if ($totalGuests instanceof \SilverStripe\ORM\FieldType\DBField) {
                            $totalGuests = $totalGuests->RAW();
                        }
                        $placesAvailable = $availableRaw + (int) $totalGuests;
                        //one extra check to make sure placesAvailable is never greater the how many places available for the tour
                        $totalSpaces = $tour->TotalSpacesAtStart;
                        if ($totalSpaces instanceof \SilverStripe\ORM\FieldType\DBField) {
                            $totalSpaces = $totalSpaces->RAW();
                        }
                        if ($placesAvailable > (int) $totalSpaces) {
                            $placesAvailable = (int) $totalSpaces;
                        }
                        
                        //admins can override the following validation
                        $adminOverrideNotSet = !(bool) $this->TotalGuestsAdminOverride;
                        $totalGuests = $this->TotalNumberOfGuests;
                        if ($totalGuests instanceof \SilverStripe\ORM\FieldType\DBField) {
                            $totalGuests = $totalGuests->RAW();
                        }
                        if ((int) $totalGuests > $placesAvailable && $adminOverrideNotSet) {
                            $result->addError(
                                'Sorry, there are not enough places available for your booking.
                                        Your booking is for ' . (int) $totalGuests . ' and the places still available is: ' . ($placesAvailable > 0 ? $placesAvailable : 0),
                                'UNIQUE_' . $this->ClassName . '_NumberOfPlacesAvailable'
                            );
                        }
                    }
                    
                    $totalGuests = $this->TotalNumberOfGuests;
                    if ($totalGuests instanceof \SilverStripe\ORM\FieldType\DBField) {
                        $totalGuests = $totalGuests->RAW();
                    }
                    if ((int) $totalGuests < 1) {
                        $result->addError(
                            'You need to have at least one person attending to make a booking.',
                            'UNIQUE_' . $this->ClassName . '_TotalNumberOfGuests'
                        );
                    }
                    
                    $numberOfChildren = $this->NumberOfChildren;
                    if ($numberOfChildren instanceof \SilverStripe\ORM\FieldType\DBField) {
                        $numberOfChildren = $numberOfChildren->RAW();
                    }
                    if ((int) $totalGuests < ((int) $numberOfChildren + 1)) {
                        $result->addError(
                            'You need to have at least one adult attending. It appears you only have children listed for this booking.',
                            'UNIQUE_' . $this->ClassName . '_NumberOfChildren'
                        );
                    }
                }
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
            
            // Replace TotalNumberOfGuests with calculated field
            $fields->replaceField(
                'TotalNumberOfGuests',
                ReadonlyField::create(
                    'TotalNumberOfGuests',
                    'Number of People',
                    $this->getTotalNumberOfGuests()->Nice()
                )->setDescription('Including children')
            );
            
            // Add NumberOfChildren field right after Number of People
            $fields->insertAfter(
                'TotalNumberOfGuests',
                ReadonlyField::create(
                    'NumberOfChildren',
                    'Number of Children',
                    $this->getNumberOfChildren()->Nice()
                )
            );
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

        // Ensure PeanutAllergyConfirmation field is visible in CMS
        if (!$fields->dataFieldByName('PeanutAllergyConfirmation')) {
            $fields->insertAfter(
                'MarketingEmailOptOut',
                CheckboxField::create(
                    'PeanutAllergyConfirmation',
                    'Peanut Allergy Confirmation'
                )->setDescription('Confirms that no one in the group is allergic to peanuts')
            );
        }

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

        // Add ticket types tab
        $fields->addFieldsToTab(
            'Root.TicketTypes',
            [
                HeaderField::create(
                    'TicketTypesHeading',
                    'Selected Ticket Types',
                    2
                ),
                $this->createTicketTypesGridField(),
                ReadonlyField::create(
                    'TicketTypesSummary',
                    'Summary',
                    $this->getTicketTypesSummary()
                ),
                ReadonlyField::create(
                    'TotalSpots',
                    'Total Spots',
                    $this->getTotalSpots()->Nice()
                ),
                ReadonlyField::create(
                    'TotalAdults',
                    'Total Adults',
                    $this->getNumberOfAdults()->Nice()
                ),
                ReadonlyField::create(
                    'TotalKids',
                    'Total Kids',
                    $this->getNumberOfKids()->Nice()
                ),
                ReadonlyField::create(
                    'TotalPrice',
                    'Total Price',
                    '$' . number_format($this->getTotalPriceFromTicketTypes(), 2)
                ),
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

        // Ensure PeanutAllergyConfirmation field is included
        if (!$fields->dataFieldByName('PeanutAllergyConfirmation')) {
            $fields->push(
                CheckboxField::create(
                    'PeanutAllergyConfirmation',
                    'I confirm no one in my group is allergic to peanuts'
                )
            );
        }

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

    //######################
    //## TicketType Methods
    //######################

    /**
     * Parse ticket type quantities from form data
     * 
     * @param array $data Form data
     * @return array Array of [TicketTypeID => Quantity]
     */
    public function parseTicketTypeQuantities(array $data): array
    {
        $quantities = [];
        
        foreach ($data as $fieldName => $value) {
            if (strpos($fieldName, 'TicketType_') === 0 && strpos($fieldName, '_Quantity') !== false) {
                // Extract ticket type ID from field name (e.g., "TicketType_123_Quantity" -> 123)
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
        
        return $quantities;
    }

    /**
     * Validate ticket type selection
     * 
     * @param array $quantities Array of [TicketTypeID => Quantity]
     * @return string|null Error message or null if valid
     */
    public function validateTicketTypes(array $quantities): ?string
    {
        if (empty($quantities)) {
            return null; // No ticket types selected is valid
        }

        $totalAdults = 0;
        $totalKids = 0;
        $totalSpots = 0;

        foreach ($quantities as $ticketTypeId => $quantity) {
            $ticketType = TicketType::get()->byID($ticketTypeId);
            if (!$ticketType) {
                continue;
            }

            // Calculate spots for this ticket type
            $adultsForThisType = $ticketType->SpotsAdults * $quantity;
            $kidsForThisType = $ticketType->SpotsKids * $quantity;
            
            $totalAdults += $adultsForThisType;
            $totalKids += $kidsForThisType;
            $totalSpots += $adultsForThisType + $kidsForThisType;
        }

        // Validate: No children without adults
        if ($totalKids > 0 && $totalAdults === 0) {
            return 'Children must be with an adult.';
        }

        // Validate: At least one spot selected
        if ($totalSpots === 0) {
            return 'Please select at least one ticket.';
        }

        // Validate against tour availability (if tour is set)
        if ($this->TourID) {
            $tour = $this->Tour();
            if ($tour && $tour->exists()) {
                $availableSpots = $tour->getNumberOfPlacesAvailable()->value;
                if ($totalSpots > $availableSpots) {
                    return "Sorry, there are only {$availableSpots} spots available for this tour.";
                }
            }
        }

        return null;
    }

    /**
     * Save ticket types with quantities to the booking
     * 
     * @param array $quantities Array of [TicketTypeID => Quantity]
     */
    public function saveTicketTypes(array $quantities): void
    {
        // Remove existing ticket types
        $this->TicketTypes()->removeAll();
        
        // Add new ticket types with quantities
        foreach ($quantities as $ticketTypeId => $quantity) {
            $ticketType = TicketType::get()->byID($ticketTypeId);
            if ($ticketType && $quantity > 0) {
                $this->TicketTypes()->add($ticketType, ['Quantity' => $quantity]);
            }
        }
    }

    /**
     * Get total adults from ticket types
     * 
     * @return int Total number of adults
     */


    public function getTotalAdultsFromTicketTypes(array $ticketTypeData = null): int
    {
        $totalAdults = 0;
        
        // If we have ticket type data passed in, use that
        if ($ticketTypeData !== null) {
            foreach ($ticketTypeData as $ticketTypeId => $quantity) {
                $ticketType = TicketType::get()->byID($ticketTypeId);
                if ($ticketType) {
                    $totalAdults += $ticketType->SpotsAdults * $quantity;
                }
            }
            return $totalAdults;
        }
        

        
        // Fallback to database relationship for saved bookings
        $ticketTypes = $this->TicketTypes();
        
        foreach ($ticketTypes as $ticketType) {
            $quantity = $ticketTypes->getExtraData('Quantity', $ticketType->ID);
            // Ensure quantity is an integer
            $quantity = is_array($quantity) ? (int)($quantity['Quantity'] ?? 0) : (int)$quantity;
            $totalAdults += $ticketType->SpotsAdults * $quantity;
        }
        
        return $totalAdults;
    }

    /**
     * Get total kids from ticket types
     * 
     * @return int Total number of kids
     */
    public function getTotalKidsFromTicketTypes(array $ticketTypeData = null): int
    {
        $totalKids = 0;
        
        // If we have ticket type data passed in, use that
        if ($ticketTypeData !== null) {
            foreach ($ticketTypeData as $ticketTypeId => $quantity) {
                $ticketType = TicketType::get()->byID($ticketTypeId);
                if ($ticketType) {
                    $totalKids += $ticketType->SpotsKids * $quantity;
                }
            }
            return $totalKids;
        }
        

        
        // Fallback to database relationship for saved bookings
        $ticketTypes = $this->TicketTypes();
        
        foreach ($ticketTypes as $ticketType) {
            $quantity = $ticketTypes->getExtraData('Quantity', $ticketType->ID);
            // Ensure quantity is an integer
            $quantity = is_array($quantity) ? (int)($quantity['Quantity'] ?? 0) : (int)$quantity;
            $totalKids += $ticketType->SpotsKids * $quantity;
        }
        
        return $totalKids;
    }

    /**
     * Get total spots from ticket types
     * 
     * @return int Total number of spots
     */
    public function getTotalSpotsFromTicketTypes(array $ticketTypeData = null): int
    {
        return $this->getTotalAdultsFromTicketTypes($ticketTypeData) + $this->getTotalKidsFromTicketTypes($ticketTypeData);
    }

    /**
     * Get total price from ticket types
     * 
     * @return float Total price
     */
    public function getTotalPriceFromTicketTypes(array $ticketTypeData = null): float
    {
        $totalPrice = 0;
        
        // If we have ticket type data passed in, use that
        if ($ticketTypeData !== null) {
            foreach ($ticketTypeData as $ticketTypeId => $quantity) {
                $ticketType = TicketType::get()->byID($ticketTypeId);
                if ($ticketType) {
                    $price = $ticketType->getPriceAmount();
                    $totalPrice += $price * $quantity;
                }
            }
            return $totalPrice;
        }
        

        
        // Fallback to database relationship for saved bookings
        $ticketTypes = $this->TicketTypes();
        
        foreach ($ticketTypes as $ticketType) {
            $quantity = $ticketTypes->getExtraData('Quantity', $ticketType->ID);
            // Ensure quantity is an integer
            $quantity = is_array($quantity) ? (int)($quantity['Quantity'] ?? 0) : (int)$quantity;
            $price = $ticketType->getPriceAmount();
            $totalPrice += $price * $quantity;
        }
        
        return $totalPrice;
    }

    /**
     * Get formatted ticket types summary for admin display
     * 
     * @return string Formatted ticket types summary
     */
    public function getTicketTypesSummary()
    {
        $ticketTypes = $this->TicketTypes();
        
        if ($ticketTypes->count() === 0) {
            return 'No ticket types selected';
        }

        $summary = [];
        foreach ($ticketTypes as $ticketType) {
            $quantity = $ticketTypes->getExtraData('Quantity', $ticketType->ID);
            // Ensure quantity is an integer
            $quantity = is_array($quantity) ? (int)($quantity['Quantity'] ?? 0) : (int)$quantity;
            if ($quantity > 0) {
                $summary[] = "{$quantity}x {$ticketType->Name}";
            }
        }

        return implode(', ', $summary);
    }

    /**
     * Create a custom GridField for ticket types that shows booking-specific quantities
     * 
     * @return GridField
     */
    protected function createTicketTypesGridField(): GridField
    {
        $gridField = GridField::create(
            'TicketTypes',
            'Ticket Types',
            $this->TicketTypes(),
            GridFieldConfig_RecordViewer::create()
        );

        // Get the data columns component and modify the display fields
        $dataColumns = $gridField->getConfig()->getComponentByType(GridFieldDataColumns::class);
        
        if ($dataColumns) {
            // Get current display fields (from summary_fields)
            $displayFields = $dataColumns->getDisplayFields($gridField);
            
            // Replace 'TotalQuantitySold' with booking-specific quantity
            if (isset($displayFields['TotalQuantitySold'])) {
                unset($displayFields['TotalQuantitySold']);
                $displayFields['BookingQuantity'] = 'Quantity Sold';
            }
            
            // Remove 'TotalRevenueFormatted' as it's not relevant for a single booking
            if (isset($displayFields['TotalRevenueFormatted'])) {
                unset($displayFields['TotalRevenueFormatted']);
            }
            
            $dataColumns->setDisplayFields($displayFields);
            
            // Add custom field formatting for booking-specific quantity
            $dataColumns->setFieldFormatting([
                'BookingQuantity' => function ($value, $item) {
                    if ($item instanceof TicketType) {
                        // Get the quantity for this ticket type in this specific booking
                        $quantity = $this->TicketTypes()->getExtraData('Quantity', $item->ID);
                        $quantity = is_array($quantity) ? (int)($quantity['Quantity'] ?? 0) : (int)$quantity;
                        return $quantity;
                    }
                    return 0;
                }
            ]);
        }

        return $gridField;
    }
}
