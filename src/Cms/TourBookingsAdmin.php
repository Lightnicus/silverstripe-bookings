<?php

namespace Sunnysideup\Bookings\Cms;

use Colymba\BulkManager\BulkAction\EditHandler;
use Colymba\BulkManager\BulkManager;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use Sunnysideup\Bookings\Forms\Actions\CloseAction;
use Sunnysideup\Bookings\Forms\Actions\OpenAction;
use Sunnysideup\Bookings\Pages\TourBookingPage;
use Sunnysideup\Bookings\Model\Booking;
use Sunnysideup\Bookings\Model\Tour;
use Sunnysideup\Bookings\Model\TourBookingSettings;
use Sunnysideup\Bookings\Model\Waitlister;

/**
 * Class \Sunnysideup\Bookings\Cms\TourBookingsAdmin
 *
 */
class TourBookingsAdmin extends ModelAdmin
{
    public $showImportForm = false;

    public $showSearchForm = true;

    private static $managed_models = [
        // Tour::class => [
        //     'dataClass' => Tour::class,
        //     'title' => 'Today',
        // ],
        // Tour::class => [
        //     'dataClass' => Tour::class,
        //     'title' => 'This week',
        // ],
        Tour::class => [
            'dataClass' => Tour::class,
            'title' => 'All future tours',
        ],
        Booking::class => [
            'dataClass' => Booking::class,
            'title' => 'All bookings',
        ],
        Waitlister::class => [
            'dataClass' => Waitlister::class,
            'title' => 'All future Waitlisters',
        ],
    ];

    private static $url_segment = 'tour-bookings-admin';

    private static $menu_title = 'Tour Bookings';

    private static $menu_icon = 'sunnysideup/bookings: client/images/icons/TourBookingsAdmin.png';

    public function getList()
    {
        $list = parent::getList();
        if (TourBookingsConfig::is_model_class($this->modelClass, Tour::class)) {
            $list = $list->filter($this->futureTourFilter());
        } elseif (TourBookingsConfig::is_model_class($this->modelClass, Booking::class) || TourBookingsConfig::is_model_class($this->modelClass, Waitlister::class)) {
            $tourIds = Tour::get()->filter($this->futureTourFilter())->columnUnique();
            $list = $list->filter(['TourID' => $tourIds]);
        }

        // Only show payment columns if payments are enabled
        if (TourBookingsConfig::is_model_class($this->modelClass, Booking::class) && TourBookingSettings::inst()->EnablePayments) {
            $list = $list->addFields([
                'PaymentStatus',
                'PaymentAmount',
                'PaymentDate',
                'PaymentReference',
            ]);
        }

        return $list;
    }

    protected function futureTourFilter(): array
    {
        $mysqlDate = date('Y-m-d', strtotime('-2 days'));
        return ['Date:GreaterThan' => $mysqlDate];
    }

    public function getEditForm($id = null, $fields = null)
    {
        $form = parent::getEditForm($id, $fields);
        if (TourBookingsConfig::is_model_class($this->modelClass, Tour::class)) {
            $fields = $form->Fields();
            $gridField = $fields->dataFieldByName($this->sanitiseClassName($this->modelClass));

            //This is just a precaution to ensure we got a GridField from dataFieldByName() which you should have
            if ($gridField && $gridField instanceof GridField) {
                $gridFieldConfig = $gridField->getConfig();
                $bmConfig = (new BulkManager([], false))
                    ->addBulkAction(EditHandler::class)
                    ->addBulkAction(CloseAction::class)
                    ->addBulkAction(OpenAction::class);
                $gridFieldConfig->addComponent($bmConfig);
                /** @var GridFieldPaginator $paginator */
                $paginator = $gridFieldConfig->getComponentByType(GridFieldPaginator::class);
                if ($paginator) {
                    $paginator->setItemsPerPage(10);
                }
                /** @var GridFieldAddNewButton $new */
                $new = $gridFieldConfig->getComponentByType(GridFieldAddNewButton::class);
                $new->setButtonName('Add one-off tour');

                $toursList1 = $fields->fieldByName('Sunnysideup-Bookings-Model-Tour');
                $fields->removeByName('Sunnysideup-Bookings-Model-Tour');

                $toursList2 = new GridField(
                    'Sunnysideup-Bookings-Model-Tour-Todays',
                    '',
                    Tour::get()->filter(['Date' => date('Y-m-d')]),
                    GridFieldConfig_RecordEditor::create()
                );
                $toursList2->setForm($form);

                $fields->insertAfter(
                    'Sunnysideup-Bookings-Model-Tour-All',
                    new TabSet(
                        'ToursSetInner',
                        new Tab('TodayTours', "Today's Tours", $toursList2),
                        new Tab('UpcomingTours', 'All Upcoming Tours', $toursList1)
                    )
                );

                $tourBookingPage = TourBookingPage::get()->first();
                $fields->insertBefore(
                    'ToursSetInner',
                    LiteralField::create(
                        'HTMLCalendarLink',
                        '<div style="text-decoration:underline"><a target="_blank" href="' . $tourBookingPage->CalendarLink() . '">View Calendar</a></div>'
                    )
                );

                $HTML_TourListNotes = '<p style="margin-top: 15px">Above is a list of all the <strong>upcoming tours</strong> for each day, some are auto-generated, some have been manually added.</p>
                                <p><strong>To set up regular tours</strong> (auto-generated), use the Tour Generator - Rules" tab in "Tour Config".</p>
                                <p><strong>Deleting tours:</strong> To delete a tour as a once off, just close the tour. To delete a recurring tour, you need to change the rules first then manually close any tours that have already been added.</p>';

                $fields->addFieldsToTab(
                    'ToursSetInner.TodayTours',
                    [
                        LiteralField::create(
                            'Bookings-Model-Tour-Description',
                            $HTML_TourListNotes
                        ),
                    ]
                );
                $fields->addFieldsToTab(
                    'ToursSetInner.UpcomingTours',
                    [
                        LiteralField::create(
                            'Bookings-Model-Tour-Description',
                            $HTML_TourListNotes
                        ),
                    ]
                );
            }

            $bookingGridfield = $fields->fieldByName('Sunnysideup-Bookings-Model-Booking');

            // Update form fields under 'Bookings'
            if ($bookingGridfield) {
                $fields->insertAfter(
                    'Sunnysideup-Bookings-Model-Booking',
                    LiteralField::create(
                        'Bookings-Model-Booking-Description',
                        '<p style="margin-top: 15px">Above is a list of all the bookings for upcoming tours.</p>
                            <p><strong>To only see bookings for individual tours:</strong></p>
                            <p>Click on a tour under the "Upcoming Tours" tab and click on the "Bookings" tab. From here you can click on a booking to see their personal details</p>'
                    )
                );
            }
        }

        return $form;
    }
}
