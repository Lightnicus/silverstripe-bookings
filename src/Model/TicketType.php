<?php

namespace Sunnysideup\Bookings\Model;

use SilverStripe\Forms\TextField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\MoneyField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBMoney;

/**
 * Class \Sunnysideup\Bookings\Model\TicketType
 *
 * @property string $Name
 * @property string $Description
 * @property string $Price
 * @property bool $Active
 * @method ManyManyList|Booking[] Bookings()
 */
class TicketType extends TourBaseClass
{
    //######################
    //## Names Section
    //######################

    private static $singular_name = 'Ticket Type';

    private static $plural_name = 'Ticket Types';

    //######################
    //## Model Section
    //######################

    private static $table_name = 'TicketType';

    private static $db = [
        'Name' => 'Varchar(255)',
        'Description' => 'Text',
        'Price' => 'Money',
        'Active' => 'Boolean',
    ];

    private static $casting = [
        'Active' => 'Boolean',
    ];

    private static $many_many = [
        'Bookings' => Booking::class,
    ];

    private static $many_many_extraFields = [
        'Bookings' => [
            'Quantity' => 'Int',
        ],
    ];

    //######################
    //## Further DB Field Details
    //######################

    private static $indexes = [
        'Active' => true,
    ];

    private static $default_sort = 'Name ASC';

    private static $required_fields = [
        'Name',
    ];

    private static $defaults = [
        'Active' => true,
    ];

    //######################
    //## Field Names and Presentation Section
    //######################

    private static $field_labels = [
        'Name' => 'Ticket Type Name',
        'Description' => 'Description',
        'Price' => 'Price',
        'Active' => 'Available for Booking',
    ];

    private static $summary_fields = [
        'Name' => 'Name',
        'Description' => 'Description',
        'PriceSummary' => 'Price',
        'ActiveNice' => 'Active',
        'TotalQuantitySold' => 'Quantity Sold',
        'TotalRevenueFormatted' => 'Total Revenue',
    ];

    private static $searchable_fields = [
        'Name' => 'PartialMatchFilter',
        'Description' => 'PartialMatchFilter',
        'Active' => 'ExactMatchFilter',
    ];

    //######################
    //## Methods Section
    //######################

    public function i18n_singular_name()
    {
        return self::$singular_name;
    }

    public function i18n_plural_name()
    {
        return self::$plural_name;
    }

    public function getTitle()
    {
        return $this->Name;
    }

    /**
     * Get the price as a formatted string
     */
    public function getPriceFormatted()
    {
        if ($this->Price && $this->Price->getAmount() > 0) {
            return $this->Price->Nice();
        }
        return 'Free';
    }

    /**
     * Get the price for summary display
     */
    public function getPriceSummary()
    {
        return $this->getPriceFormatted();
    }

    /**
     * Get the price amount as decimal
     */
    public function getPriceAmount()
    {
        if ($this->Price) {
            return $this->Price->getAmount();
        }
        return 0;
    }

    /**
     * Check if this ticket type is available for booking
     */
    public function isAvailable()
    {
        return $this->Active;
    }

    /**
     * Get all active ticket types
     */
    public static function getActive()
    {
        return self::get()->filter(['Active' => true]);
    }

    /**
     * Get formatted active status for display
     */
    public function getActiveNice()
    {
        return $this->Active ? 'Yes' : 'No';
    }

    /**
     * Get the total quantity of this ticket type sold across all bookings
     */
    public function getTotalQuantitySold()
    {
        $total = 0;
        foreach ($this->Bookings() as $booking) {
            $extraFields = $this->Bookings()->getExtraData('BookingID', $booking->ID);
            $quantity = isset($extraFields['Quantity']) ? (int)$extraFields['Quantity'] : 0;
            $total += $quantity;
        }
        return $total;
    }

    /**
     * Get the total revenue from this ticket type
     */
    public function getTotalRevenue()
    {
        $totalQuantity = $this->getTotalQuantitySold();
        $pricePerTicket = $this->getPriceAmount();
        return $totalQuantity * $pricePerTicket;
    }

    /**
     * Get formatted total revenue for display
     */
    public function getTotalRevenueFormatted()
    {
        $revenue = $this->getTotalRevenue();
        if ($revenue > 0) {
            return '$' . number_format($revenue, 2);
        }
        return '$0.00';
    }



    //######################
    //## Permissions Section
    //######################

    public function canCreate($member = null, $context = [])
    {
        return true;
    }

    public function canView($member = null, $context = [])
    {
        return true;
    }

    public function canEdit($member = null, $context = [])
    {
        return true;
    }

    public function canDelete($member = null, $context = [])
    {
        // Don't allow deletion if there are bookings using this ticket type
        if ($this->Bookings()->count() > 0) {
            return false;
        }
        return true;
    }

    //######################
    //## CMS Section
    //######################

    public function getCMSFields()
    {
        $fields = FieldList::create(
            TabSet::create('Root', Tab::create('Main'))
        );

        $fields->addFieldToTab('Root.Main', TextField::create('Name', 'Ticket Type Name')
            ->setDescription('Enter a descriptive name for this ticket type (e.g., "Adult", "Child", "Senior", "VIP")')
        );

        $fields->addFieldToTab('Root.Main', TextareaField::create('Description', 'Description')
            ->setDescription('Optional description of this ticket type. This can be displayed to customers during booking.')
        );

                       $fields->addFieldToTab('Root.Main', MoneyField::create('Price', 'Price')
                   ->setDescription('Enter the price in dollars. Use 0 for free tickets.')
               );

        $fields->addFieldToTab('Root.Main', CheckboxField::create('Active', 'Available for Booking')
            ->setDescription('Uncheck to temporarily disable this ticket type from being selected during booking')
        );

        // Add helpful information
        $fields->addFieldToTab('Root.Main', HeaderField::create('InfoHeader', 'Information', 4));
        $fields->addFieldToTab('Root.Main', LiteralField::create('InfoText', 
            '<p><strong>Note:</strong> Only active ticket types will be available for customers to select during booking.</p>'
        ));

        return $fields;
    }

    //######################
    //## Events Section
    //######################

    protected function onBeforeWrite()
    {
        parent::onBeforeWrite();
        
        // Ensure price is not negative
        if ($this->Price && $this->Price->getAmount() < 0) {
            $this->Price->setAmount(0);
        }
    }

    protected function onAfterWrite()
    {
        parent::onAfterWrite();
    }

    protected function onBeforeDelete()
    {
        parent::onBeforeDelete();
        
        // If there are bookings using this ticket type, don't allow deletion
        if ($this->Bookings()->count() > 0) {
            throw new \Exception('Cannot delete ticket type that has associated bookings.');
        }
    }

    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();

        // Only create default records if no ticket types exist
        if (self::get()->count() === 0) {
            $defaultTypes = [
                [
                    'Name' => 'Adult',
                    'Description' => 'Standard adult ticket for ages 13 and above',
                    'Price' => 25.00,
                    'Active' => true,
                ],
                [
                    'Name' => 'Child (under 12)',
                    'Description' => 'Discounted ticket for children under 12 years old',
                    'Price' => 15.00,
                    'Active' => true,
                ],
                [
                    'Name' => 'Senior (65+)',
                    'Description' => 'Discounted ticket for seniors aged 65 and above',
                    'Price' => 20.00,
                    'Active' => true,
                ],
                [
                    'Name' => 'VIP Experience',
                    'Description' => 'Premium experience with exclusive access and personalized service',
                    'Price' => 50.00,
                    'Active' => true,
                ],
            ];

            foreach ($defaultTypes as $typeData) {
                $ticketType = self::create();
                $ticketType->Name = $typeData['Name'];
                $ticketType->Description = $typeData['Description'];
                $ticketType->Price = \SilverStripe\ORM\FieldType\DBMoney::create_field('Money', $typeData['Price'], 'NZD');
                $ticketType->Active = $typeData['Active'];
                $ticketType->write();
            }

            DB::alteration_message('Created default ticket types', 'created');
        }
    }
} 