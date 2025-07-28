<?php

namespace Sunnysideup\Bookings\Cms;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldExportButton;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldFilterHeader;
use SilverStripe\Forms\GridField\GridFieldImportButton;
use SilverStripe\Forms\GridField\GridFieldPrintButton;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\Forms\LiteralField;
use Sunnysideup\Bookings\Model\Booking;
use Sunnysideup\Bookings\Model\DateInfo;
use Sunnysideup\Bookings\Model\ReferralOption;
use Sunnysideup\Bookings\Model\TimesForTour;
use Sunnysideup\Bookings\Model\TicketType;
use Sunnysideup\Bookings\Model\Tour;
use Sunnysideup\Bookings\Model\TourBookingSettings;
use Sunnysideup\Bookings\Model\Waitlister;
use Sunnysideup\Bookings\Pages\TourBookingPage;
use Sunnysideup\Bookings\Tasks\MonthlyTourReport;
use Sunnysideup\Bookings\Tasks\YearlyTourReport;
use Sunnysideup\Bookings\Tasks\TourBuilder;
use UndefinedOffset\SortableGridField\Forms\GridFieldSortableRows;

/**
 * Class \Sunnysideup\Bookings\Cms\TourBookingsConfig
 *
 */
class TourBookingsConfig extends ModelAdmin
{
    public $showImportForm = false;

    public $showSearchForm = [
        DateInfo::class,
        TimesForTour::class,
        TicketType::class,
        ReferralOption::class,
        Tour::class,
        Booking::class,
        Waitlister::class,
    ];

    private static $managed_models = [
        TourBookingSettings::class => [
            'dataClass' => TourBookingSettings::class,
            'title' => 'Settings',
        ],
        DateInfo::class => [
            'dataClass' => DateInfo::class,
            'title' => 'Tour Generator - Rules',
        ],
        TimesForTour::class => [
            'dataClass' => TimesForTour::class,
            'title' => 'Tour Times',
        ],
        TicketType::class => [
            'dataClass' => TicketType::class,
            'title' => 'Ticket Types',
        ],
        ReferralOption::class => [
            'dataClass' => ReferralOption::class,
            'title' => 'Referral options',
        ],
        Tour::class => [
            'dataClass' => Tour::class,
            'title' => 'Tour Archive',
        ],
        Booking::class => [
            'dataClass' => Booking::class,
            'title' => 'Booking Archive',
        ],
        Waitlister::class => [
            'dataClass' => Waitlister::class,
            'title' => 'Waitlists Archive',
        ],
    ];

    private static $url_segment = 'tour-bookings-config';

    private static $menu_title = 'Tour Config';

    private static $menu_icon = 'sunnysideup/bookings: client/images/icons/TourBookingsAdmin.png';

    public function getEditForm($id = null, $fields = null)
    {
        $form = parent::getEditForm($id, $fields);
        $fields = $form->Fields();
        $gridField = $fields->dataFieldByName($this->sanitiseClassName($this->modelClass));

        // extra check for pseudo managed model (Booking -> Tour)
        if (!$gridField) {
            $gridField = $fields->dataFieldByName($this->sanitiseClassName(Booking::class));
        }

        $gridFieldConfig = null;
        if ($gridField && $gridField instanceof GridField) {
            $gridFieldConfig = $gridField->getConfig();
        }
        //This check is simply to ensure you are on the managed model you want adjust accordingly
        if (self::is_model_class($this->modelClass, TimesForTour::class)) {
            //This is just a precaution to ensure we got a GridField from dataFieldByName() which you should have
            if ($gridFieldConfig) {
                $gridFieldConfig->removeComponentsByType(GridFieldExportButton::class);
            }
        }

        if (self::is_model_class($this->modelClass, DateInfo::class)) {
            //This is just a precaution to ensure we got a GridField from dataFieldByName() which you should have
            if ($gridFieldConfig) {
                $gridFieldConfig->removeComponentsByType(GridFieldExportButton::class);
                $gridFieldConfig->removeComponentsByType(GridFieldPrintButton::class);
                $gridFieldConfig->addComponent(new GridFieldSortableRows('SortOrder'));
            }
            $this->addRulesExplanations($fields);
        }

        if (self::is_model_class($this->modelClass, TourBookingSettings::class)) {
            if ($gridFieldConfig) {
                $gridFieldConfig->removeComponentsByType(GridFieldExportButton::class);
                $gridFieldConfig->removeComponentsByType(GridFieldPrintButton::class);
                $gridFieldConfig->removeComponentsByType(GridFieldImportButton::class);
                $gridFieldConfig->removeComponentsByType(GridFieldFilterHeader::class);
                $gridFieldConfig->removeComponentsByType(GridFieldSortableHeader::class);
            }
            $this->addConfigExplanations($fields);

            return $form;
        }

        if (self::is_model_class($this->modelClass, ReferralOption::class)) {
            if ($gridFieldConfig) {
                $gridFieldConfig->removeComponentsByType(GridFieldExportButton::class);
                $gridFieldConfig->removeComponentsByType(GridFieldPrintButton::class);
                $gridFieldConfig->addComponent(new GridFieldSortableRows('SortOrder'));
            }
        }

        if (self::is_model_class($this->modelClass, TicketType::class)) {
            if ($gridFieldConfig) {
                $gridFieldConfig->removeComponentsByType(GridFieldExportButton::class);
                $gridFieldConfig->removeComponentsByType(GridFieldPrintButton::class);
            }
            $this->addTicketTypeExplanations($fields);
        }

        if (self::is_model_class($this->modelClass, Tour::class)) {
            // Remove Add new item button
            if ($gridFieldConfig) {
                $gridFieldConfig->removeComponentsByType(GridFieldAddNewButton::class);
            }
        }

        return $form;
    }

    public static function is_model_class(string $modelClass, string $className): bool
    {
        return is_subclass_of($modelClass, $className) || $className === $modelClass;
    }

    protected function addConfigExplanations($fields)
    {
        $bookingSingleton = Injector::inst()->get(Booking::class);
        $timesForTourSingleton = Injector::inst()->get(TimesForTour::class);
        $dateInfoSingleton = Injector::inst()->get(DateInfo::class);
        $tourSingleton = Injector::inst()->get(Tour::class);
        $createToursLink = Injector::inst()->get(TourBuilder::class)->Link();
        $page = TourBookingPage::get()->first();
        if ($page) {
            $this->addUsefulLinkToFields(
                $fields,
                'Open website tour page',
                $page->Link()
            );
            $this->addUsefulLinkToFields(
                $fields,
                'Open calendar',
                $page->CalendarLink()
            );
        }

        $this->addUsefulLinkToFields(
            $fields,
            'Create new booking',
            $page->Link(),
            'The best way to add a booking is to use the front-end of the website.'
        );

        $this->addUsefulLinkToFields(
            $fields,
            'Create new tour rule',
            $dateInfoSingleton->CMSAddLink(),
            'Add new tour date(s) with all the details and then create the tours using the <a href="' . $createToursLink . '">create tours button</a>.'
        );

        $this->addUsefulLinkToFields(
            $fields,
            'Create new tour time',
            $timesForTourSingleton->CMSAddLink(),
            'Add the new time first and then add the tour dates.
            After that you will have to create the tours using the <a href="' . Injector::inst()->get(TourBuilder::class)->Link() . '">create tours button</a>.'
        );

        $this->addUsefulLinkToFields(
            $fields,
            'See which rule applies to a specific date',
            $dateInfoSingleton->CMSListLink(),
            'Click on the magnifying glass and search for a particular day.'
        );

        $this->addUsefulLinkToFields(
            $fields,
            'Run tour generator and create future tours',
            $createToursLink,
            'This task runs regularly, but you can run it now by clicking above link.'
        );

        $this->addUsefulLinkToFields(
            $fields,
            'Generate monthly tour report',
            Injector::inst()->get(MonthlyTourReport::class)->Link(),
            'Show last months statistics.'
        );

        $this->addUsefulLinkToFields(
            $fields,
            'Generate yearly tour report',
            Injector::inst()->get(YearlyTourReport::class)->Link(),
            'Show statistics for the year.'
        );
    }

    protected function addUsefulLinkToFields(FieldList $fields, string $title, string $link, ?string $explanation = '')
    {
        $name = preg_replace('#[^A-Za-z0-9 ]#', '', $title);
        $fields->push(
            LiteralField::create(
                $name . '_UseFulLink',
                '<h2>› <a href="' . $link . '">' . $title . '</a></h2><p>' . $explanation . '</p>'
            ),
        );
    }

    protected function addRulesExplanations($fields)
    {
        $fields->insertAfter(
            $this->sanitiseClassName($this->modelClass),
            LiteralField::create(
                'Explanation',
                '

                      <p style="margin-top: 15px">Above is a list of rules that automatically generate tours for each day.</p>

                      <p>
                        <strong>How it works:</strong><br>
                        Going from the bottom up, the generator looks to find any rule that matches the current day. As soon as it finds a match, it stops and adds a tour at the time(s) specified in the rule. Only one rule is used for each day.
                      </p>

                      <p>
                        <strong>This means:</strong><br>
                        Standard, recurring rules should be at the top. E.g. Mon - Friday, Sat, Sun<br>
                        Special exception rules should be at the button. E.g. Waitangi Day, School Holidays<br>
                        To reorder rule, check the "Allow drag and drop re-ordering" checkbox and drag a rule up or down.
                      </p>

                      <p>
                        <strong>Editing rules:</strong><br>
                        Click on a rule to edit it. Here you can change its Start and End date, Frequency, etc
                      </p>
                '
            )
        );
    }

    protected function addTicketTypeExplanations($fields)
    {
        $fields->insertAfter(
            $this->sanitiseClassName($this->modelClass),
            LiteralField::create(
                'TicketTypeExplanation',
                '

                      <p style="margin-top: 15px">Above is a list of ticket types that customers can select when booking tours.</p>

                      <p>
                        <strong>How it works:</strong><br>
                        Each ticket type has a name, price, and active status. Only active ticket types will be available for customers to select during the booking process.
                      </p>

                      <p>
                        <strong>Examples:</strong><br>
                        • Adult - $25.00<br>
                        • Child (under 12) - $15.00<br>
                        • Senior (65+) - $20.00<br>
                        • VIP Experience - $50.00<br>
                        • Free Tour - $0.00
                      </p>

                      <p>
                        <strong>Tips:</strong><br>
                        • Use descriptive names that customers will understand<br>
                        • Set price to 0 for free tickets<br>
                        • Uncheck "Active" to temporarily disable a ticket type<br>
                        • Ticket types cannot be deleted if they have associated bookings
                      </p>
                '
            )
        );
    }
}
