<?php

/**
 * Calendar page
 *
 * @author Aaron Carlino
 * @author Grant Heggie
 * @package silverstripe-event-calendar
 */

namespace UncleCheese\EventCalendar\Pages;

use Carbon\Carbon;
use ICal\ICal;
use Throwable;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextField;

use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\View\Requirements;
use UncleCheese\EventCalendar\Models\CachedCalendarEntry;
use UncleCheese\EventCalendar\Models\CalendarAnnouncement;
use UncleCheese\EventCalendar\Models\Region;
use UncleCheese\EventCalendar\Helpers\ICSFeedReader;
use UncleCheese\EventCalendar\Models\ICSFeed;
use UncleCheese\EventCalendar\Views\CalendarWidget;
use Page;

class Calendar extends Page
{
    private static $table_name = 'UncleCheese_Calendar';

    private static $singular_name = 'Calendar';

    private static $plural_name = 'Calendars';

    private static $db = [
        'DefaultDateHeader'		=> 'Varchar(50)',
        'OtherDatesCount'		=> 'Int',
        'RSSTitle'				=> 'Varchar(255)',
        'DefaultFutureMonths'	=> 'Int',
        'EventsPerPage'			=> 'Int',
        'DefaultView'			=> "Enum('today,week,month,weekend,upcoming','upcoming')"
    ];

    private static $has_many = [
        'Announcements'	=> CalendarAnnouncement::class,
        'Feeds'			=> ICSFeed::class
    ];

    private static $many_many = [
        'NestedCalendars' => self::class
    ];

    private static $belongs_many_many = [
        'ParentCalendars' => self::class . '.NestedCalendars'
    ];

    private static $allowed_children = [
        CalendarEvent::class
    ];

    private static $defaults = [
        'DefaultDateHeader'		=> 'Upcoming events',
        'OtherDatesCount'		=> 3,
        'DefaultFutureMonths'	=> 6,
        'EventsPerPage'			=> 10,
        'DefaultView'			=> 'upcoming'
    ];

    private static $icon = "sunnysideup/silverstripe-event-calendar:client/dist/images/calendar-file.gif";

    private static $description = "A collection of calendar events";

    /**
     * @var int
     */
    private static $recurring_event_index = 0;

    /**
     * @var int
     */
    private static $recent_events_default_limit = 9999;

    /**
     * @var int
     */
    private static $max_recurring_events_listed = 0;

    /**
     * @var string
     */
    private static $event_class = CalendarEvent::class;

    /**
     * @var string
     */
    private static $announcement_class = CalendarAnnouncement::class;

    /**
     * @var string
     */
    private static $timezone = "America/New_York";

    /**
     * @var string
     */
    private static $language = "EN";

    /**
     * @var bool
     */
    private static $jquery_included = false;

    /**
     * @var bool
     */
    private static $include_default_css = false;

    /**
     * @var bool
     */
    private static $include_calendar_js = false;

    /**
     * @var bool
     */
    private static $include_rss_link = true;

    /**
     * @var bool
     */
    private static $caching_enabled = false;

    /**
     * Number of years that are cached by the CachedCalendarBuildTask
     *
     * @var int
     */
    private static $cache_future_years = 2;

    /**
     * The longest period (in days) that is expanded for recurring events and feeds in one request.
     * Protects the website against requests for ridiculously long date ranges.
     *
     * @var int
     */
    private static $max_date_range_days = 1100;

    /**
     * How far (in days) we look ahead for the next recurrences of an event.
     *
     * @var int
     */
    private static $max_recurrence_lookahead_days = 3650;

    protected $eventClass_cache;
    protected $announcementClass_cache;
    protected $datetimeClass_cache;
    protected $dateToEventRelation_cache;
    protected $announcementToCalendarRelation_cache;
    protected $eventList_cache;

    /**
     * @return void
     */
    public static function set_jquery_included($bool = true)
    {
        Config::modify()->set(self::class, 'jquery_included', $bool);
    }

    /**
     * @return void
     */
    public static function enable_caching()
    {
        Config::modify()->set(self::class, 'caching_enabled', true);
    }

    public function getCMSFields()
    {
        $this->beforeUpdateCMSFields(function ($f) {
            Requirements::javascript('sunnysideup/silverstripe-event-calendar:client/dist/js/calendar_cms.js');

            $configuration = _t(__CLASS__.'.CONFIGURATION', 'Configuration');
            $f->addFieldsToTab(
                "Root.$configuration",
                [
                    DropdownField::create(
                        'DefaultView',
                        _t(__CLASS__.'.DEFAULTVIEW', 'Default view'),
                        [
                            'upcoming' => _t(__CLASS__.'.UPCOMINGVIEW', "Show a list of upcoming events."),
                            'month' => _t(__CLASS__.'.MONTHVIEW', "Show this month's events."),
                            'week' => _t(__CLASS__.'.WEEKVIEW', "Show this week's events. If none, fall back on this month's"),
                            'today' => _t(__CLASS__.'.TODAYVIEW', "Show today's events. If none, fall back on this week's events"),
                            'weekend' => _t(__CLASS__.'.WEEKENDVIEW', "Show this weekend's events.")
                        ]
                    )->addExtraClass('defaultView'),
                    NumericField::create('DefaultFutureMonths', _t(__CLASS__.'.DEFAULTFUTUREMONTHS', 'Number maximum number of future months to show in default view'))->addExtraClass('defaultFutureMonths'),
                    NumericField::create('EventsPerPage', _t(__CLASS__.'.EVENTSPERPAGE', 'Events per page')),
                    TextField::create('DefaultDateHeader', _t(__CLASS__.'.DEFAULTDATEHEADER', 'Default date header (displays when no date range has been selected)')),
                    NumericField::create('OtherDatesCount', _t(__CLASS__.'.NUMBERFUTUREDATES', 'Number of future dates to show for repeating events'))
                ]
            );

            // Announcements
            $announcements = _t(__CLASS__.'.Announcements', 'Announcements');
            $f->addFieldToTab(
                "Root.$announcements",
                GridField::create(
                    "Announcements",
                    $announcements,
                    $this->Announcements(),
                    GridFieldConfig_RecordEditor::create()
                )->setDescription(
                    _t(__CLASS__.'.ANNOUNCEMENTDESCRIPTION', 'Announcements are simple entries you can add to your calendar that do not have detail pages, e.g. "Office closed"')
                )
            );

            // Feeds
            $feeds = _t(__CLASS__.'.FEEDS', 'Feeds');
            $f->addFieldToTab(
                "Root.$feeds",
                GridField::create(
                    "Feeds",
                    $feeds,
                    $this->Feeds(),
                    GridFieldConfig_RecordEditor::create()
                )->setDescription(
                    _t(__CLASS__.'.ICSFEEDDESCRIPTION', 'Add ICS feeds to your calendar to include events from external sources')
                )
            );

            $otherCals = self::get()->exclude("ID", $this->ID);
            if ($otherCals->exists()) {
                $f->addFieldToTab(
                    "Root.$feeds",
                    CheckboxSetField::create(
                        'NestedCalendars',
                        _t(__CLASS__.'.NESTEDCALENDARS', 'Include events from these calendars'),
                        $otherCals->map('ID', 'Link')
                    )
                );
            }
            $f->addFieldToTab(
                "Root.Main",
                TextField::create('RSSTitle', _t(__CLASS__.'.RSSTITLE', 'Title of RSS feed')),
                'Content'
            );
            $f->addFieldToTab(
                'Root.RegionList',
                GridField::create(
                    'Regions',
                    'Edit Regions',
                    Region::get(),
                    GridFieldConfig_RecordEditor::create()
                )
            );
        });

        return parent::getCMSFields();
    }

    /**
     * @return string
     */
    public function getEventClass()
    {
        if ($this->eventClass_cache) {
            return $this->eventClass_cache;
        }
        return $this->eventClass_cache = self::config()->event_class;
    }

    /**
     * @return string
     */
    public function getAnnouncementClass()
    {
        if ($this->announcementClass_cache) {
            return $this->announcementClass_cache;
        }
        return $this->announcementClass_cache = self::config()->announcement_class;
    }

    /**
     * @return string
     */
    public function getDateTimeClass()
    {
        if ($this->datetimeClass_cache) {
            return $this->datetimeClass_cache;
        };
        return $this->datetimeClass_cache = Config::inst()
            ->get($this->getEventClass(), 'datetime_class');
    }

    /**
     * @return string
     */
    public function getDateToEventRelation(): string
    {
        if ($this->dateToEventRelation_cache) {
            return (string) $this->dateToEventRelation_cache;
        }
        $dateTime = singleton($this->getDateTimeClass());
        foreach ($dateTime->config()->has_one as $rel => $class) {
            if ($class == $this->getEventClass()) {
                return (string) $this->dateToEventRelation_cache = $rel.'ID';
            }
        }
        return '';
    }

    /**
     * Returns the date (Y-m-d) if it is a valid date, null otherwise.
     *
     * @param mixed $date
     * @return string|null
     */
    protected static function cleanDate($date): ?string
    {
        $date = trim((string) $date);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $matches)
            && checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
        ) {
            return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
        }

        return null;
    }

    /**
     * @param string $start Start date
     * @param string $end End date
     * @param string|array|null $filter not used for cached events
     * @param int $limit Limit result set size
     * @return DataList
     */
    public function getCachedEventList($start, $end, $filter = null, $limit = null)
    {
        $list = CachedCalendarEntry::get()
            ->filter("CachedCalendarID", $this->ID);
        $start = self::cleanDate($start);
        $end = self::cleanDate($end);
        if ($end) {
            $list = $list->filter("StartDate:LessThanOrEqual", $end);
        }
        if ($start) {
            $list = $list->filter("EndDate:GreaterThanOrEqual", $start);
        }

        return $list
            ->sort(
                [
                    "StartDate" => "ASC",
                    "StartTime" => "ASC"
                ]
            )
            ->limit($limit);
    }

    /**
     * Get the event list for this calendar, including standard, recurring and announcements
     *
     * The filters are added to a query as is, so use parameterised filters
     * (e.g. ['"SiteTree"."Title" LIKE ?' => ['%' . $term . '%']]) when they include user input.
     *
     * @param string $start Start date
     * @param string $end End date
     * @param string|array $filter Additional filters
     * @param int $limit Limit result set size
     * @param string|array $announcementFilter Filter announcements prior to their inclusion
     * @return DataList|ArrayList
     */
    public function getEventList(
        $start,
        $end,
        $filter = null,
        $limit = null,
        $announcementFilter = null
    ) {
        if (self::config()->caching_enabled) {
            return $this->getCachedEventList($start, $end, $filter, $limit);
        }

        $start = self::cleanDate($start);
        $end = self::cleanDate($end);

        $eventList = ArrayList::create();

        foreach ($this->getAllCalendars() as $calendar) {
            if ($events = $calendar->getStandardEvents($start, $end, $filter)) {
                $eventList->merge($events);
            }
            $announcements = DataList::create($this->getAnnouncementClass())
                ->filter(['CalendarID' => $calendar->ID]);
            if ($start) {
                $announcements = $announcements->filter('StartDate:GreaterThanOrEqual', $start);
            }
            if ($end) {
                $announcements = $announcements->filter('EndDate:LessThanOrEqual', $end);
            }
            if ($announcementFilter) {
                $announcements = $announcements->where($announcementFilter);
            }
            foreach ($announcements as $announcement) {
                $eventList->push($announcement);
            }
            if ($recurring = $calendar->getRecurringEvents($filter)) {
                $calendar->addRecurringEvents($start, $end, $recurring, $eventList);
            }
            if ($feedevents = $calendar->getFeedEvents($start, $end)) {
                $eventList->merge($feedevents);
            }
        }
        $eventList = $eventList->sort(
            [
                'StartDate' => 'ASC',
                'StartTime' => 'ASC'
            ]
        )->limit($limit);

        return $this->eventList_cache = $eventList;
    }

    /**
     * Get non-recurring events for this calendar that fall within a start and end date
     *
     * @param string $start Start date
     * @param string $end End date
     * @param string|array $filter Additional filters
     * @return DataList
     */
    protected function getStandardEvents($start, $end, $filter = null)
    {
        $eventTable = Config::inst()->get($this->getEventClass(), 'table_name');
        $relation = $this->getDateToEventRelation();
        $siteTreeTable = SiteTree::config()->table_name;
        $dateTimeClass = $this->getDateTimeClass();
        $schema = DataObject::getSchema();
        $startField = '"' . $schema->tableForField($dateTimeClass, 'StartDate') . '"."StartDate"';
        $endField = '"' . $schema->tableForField($dateTimeClass, 'EndDate') . '"."EndDate"';

        $list = DataList::create($dateTimeClass)
            ->innerJoin($eventTable, "\"$relation\" = \"$eventTable\".\"ID\"")
            ->innerJoin($siteTreeTable, "\"$siteTreeTable\".\"ID\" = \"$eventTable\".\"ID\"");

        $filters = [
            'Recursion:not' => 1
        ];

        $ids = $this->AllChildren()->column('ID');
        if ($ids) {
            $filters[$relation] = $ids;
        }

        $list = $list->filter($filters);

        if ($start && $end) {
            $list = $list->where([
                "($startField <= ? AND $endField >= ?) OR ($startField BETWEEN ? AND ?) OR ($endField BETWEEN ? AND ?)"
                => [$start, $end, $start, $end, $start, $end]
            ]);
        } elseif ($start) {
            $list = $list->where(["($startField >= ? OR $endField > ?)" => [$start, $start]]);
        } elseif ($end) {
            $list = $list->where(["($endField <= ? OR $startField < ?)" => [$end, $end]]);
        }
        if ($filter) {
            $list = $list->where($filter);
        }

        return $list;
    }

    /**
     * Get the list of events in this calendar that are set to recur
     *
     * @param string|array $filter Additional filters
     * @return DataList|false
     */
    protected function getRecurringEvents($filter = null)
    {
        if ($relation = $this->getDateToEventRelation()) {
            $dtTable = Config::inst()->get($this->getDateTimeClass(), 'table_name');
            $siteTreeTable = SiteTree::config()->table_name;
            $events = DataList::create($this->getEventClass())
                ->filter(
                    [
                        "Recursion" => "1",
                        "ParentID" => $this->ID
                    ]
                )
                ->innerJoin($dtTable, "\"$dtTable\".\"$relation\" = \"$siteTreeTable\".\"ID\"");
            if ($filter) {
                $events = $events->where($filter);
            }
            return $events;
        }

        return false;
    }

    /**
     * Get the next set of recurring datetimes for an event
     *
     * @param CalendarEvent $eventObj Event record
     * @param CalendarDateTime $datetimeObj Datetime record
     * @param int $limit Limit the result set size
     * @return ArrayList
     */
    public function getNextRecurringEvents($eventObj, $datetimeObj, $limit = null)
    {
        $counter = Carbon::parse($datetimeObj->StartDate);

        if ($event = $datetimeObj->Event()->DateTimes()->First()) {
            $endDate = strtotime((string) $event->EndDate);
        } else {
            $endDate = false;
        }
        $counter->addDay()->startOfDay();
        $dates = ArrayList::create();
        $wanted = max(0, (int) $this->OtherDatesCount);
        $maxDays = max(1, (int) $this->config()->max_recurrence_lookahead_days);
        $reader = $eventObj->getRecursionReader();
        for ($i = 0; $i < $maxDays && $dates->Count() < $wanted; $i++) {
            // check the end date
            if ($endDate && $endDate > 0 && $endDate <= $counter->getTimestamp()) {
                break;
            }
            if ($reader->recursionHappensOn($counter->getTimestamp())) {
                $dates->push($this->newRecursionDateTime($datetimeObj, $counter->toDateString()));
            }
            $counter->addDay()->startOfDay();
        }

        return $dates;
    }

    /**
     * Add recurring events to a list of events
     *
     * @param string $startDate Start date
     * @param string $endDate End date
     * @param DataList $recurringEvents Set of recurring events
     * @param ArrayList $allEvents The list to add events to
     */
    protected function addRecurringEvents($startDate, $endDate, $recurringEvents, $allEvents)
    {
        $rangeStart = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfDay();
        $rangeEnd = $endDate
            ? Carbon::parse($endDate)->startOfDay()
            : $rangeStart->copy()->addMonths(max(1, (int) $this->DefaultFutureMonths));
        $maxDays = max(1, (int) $this->config()->max_date_range_days);
        $latestEnd = $rangeStart->copy()->addDays($maxDays);
        if ($rangeEnd > $latestEnd) {
            $rangeEnd = $latestEnd;
        }

        $maxListed = (int) self::config()->max_recurring_events_listed;

        foreach ($recurringEvents as $recurringEvent) {
            $reader = $recurringEvent->getRecursionReader();
            $relation = null;
            foreach ($recurringEvent->config()->has_many as $rel => $class) {
                if ($class == $this->getDateTimeClass()) {
                    $relation = $rel;
                }
            }
            if (!$relation) {
                continue;
            }
            $recurringEventDatetimes = $recurringEvent->$relation()->filter(
                [
                    'StartDate:LessThanOrEqual' => $rangeEnd->toDateString(),
                    'EndDate:GreaterThanOrEqual' => $rangeStart->toDateString()
                ]
            );

            foreach ($recurringEventDatetimes as $recurringEventDatetime) {
                $dateCounter = $rangeStart->copy();
                $start = Carbon::parse($recurringEventDatetime->StartDate)->startOfDay();
                if ($start > $dateCounter) {
                    $dateCounter = $start;
                }
                $endStamp = $recurringEventDatetime->EndDate
                    ? strtotime((string) $recurringEventDatetime->EndDate)
                    : false;
                $recurrenceAdded = 0;
                while ($dateCounter <= $rangeEnd) {
                    if ($maxListed && $recurrenceAdded == $maxListed) {
                        break;
                    }
                    // check the end date
                    if ($endStamp && $endStamp > 0 && $endStamp < $dateCounter->getTimestamp()) {
                        break;
                    }
                    if ($reader->recursionHappensOn($dateCounter->getTimestamp())) {
                        $e = $this->newRecursionDateTime($recurringEventDatetime, $dateCounter->toDateString());
                        $allEvents->push($e);
                        $recurrenceAdded++;
                    }
                    $dateCounter->addDay()->startOfDay();
                }
            }
        }
    }

    /**
     * Create a Datetime for a recursion instance (recurrence)
     *
     * @param mixed $recurringEventDatetime The Datetime to base the recurrence off
     * @param string $startDate The start date
     * @return mixed An instance of the class defined in CalendarEvent::datetime_class
     */
    public function newRecursionDateTime($recurringEventDatetime, $startDate)
    {
        $relation = $this->getDateToEventRelation();
        $e = Injector::inst()->get($this->getDateTimeClass(), false);
        foreach ($recurringEventDatetime->config()->db as $field => $type) {
            $e->$field = $recurringEventDatetime->$field;
        }
        $e->DateTimeID = $recurringEventDatetime->ID;
        $e->StartDate = $startDate;
        $e->EndDate = $startDate;
        $e->$relation = $recurringEventDatetime->$relation;
        $e->ID = "recurring" . self::$recurring_event_index;
        self::$recurring_event_index++;
        return $e;
    }

    /**
     * Get events from specified feeds that fall within a date range
     *
     * @param string $startDate Range start date
     * @param string $endDate Range end date
     * @return ArrayList
     */
    public function getFeedEvents($startDate, $endDate)
    {
        $feedevents = ArrayList::create();
        $feeds = $this->Feeds();
        if (!$feeds->exists()) {
            return $feedevents;
        }

        $startDate = self::cleanDate($startDate) ?: Carbon::now()->toDateString();
        // single day views don't pass end dates
        $endDate = self::cleanDate($endDate) ?: $startDate;
        $timezone = new \DateTimeZone($this->config()->timezone);
        $reader = ICSFeedReader::create();

        foreach ($feeds as $feed) {
            $body = $reader->read($feed->URL);
            if (!$body) {
                continue;
            }
            try {
                $feedreader = new ICal(false, ['defaultTimeZone' => $timezone->getName()]);
                $feedreader->initString($body);
                $icalEvents = $feedreader->eventsFromRange($startDate . ' 00:00:00', $endDate . ' 23:59:59');
            } catch (Throwable $e) {
                continue;
            }
            foreach ($icalEvents as $event) {
                if (empty($event->dtstart_array[2])) {
                    continue;
                }
                $startdatetime = (new \DateTime('@' . $event->dtstart_array[2]))->setTimezone($timezone);
                $enddatetime = !empty($event->dtend_array[2])
                    ? (new \DateTime('@' . $event->dtend_array[2]))->setTimezone($timezone)
                    : clone $startdatetime;

                // all day events end at the start of the next day
                $allDay = isset($event->dtstart_array[1]) && preg_match('/^\d{8}$/', (string) $event->dtstart_array[1]);
                if ($allDay && $enddatetime > $startdatetime) {
                    $enddatetime->modify('-1 day');
                }

                // translate iCal schema into CalendarAnnouncement schema (datetime + title/content)
                $feedevent = CalendarAnnouncement::create()
                    ->update(
                        [
                            'ID' => 'ICS_' . $feed->ID,
                            'Feed' => true,
                            'CalendarID' => $this->ID,
                            'Title' => (string) ($event->summary ?? ''),
                            'Content' => (string) ($event->description ?? ''),
                            'AllDay' => $allDay ? 1 : 0,
                            'StartDate' => $startdatetime->format('Y-m-d'),
                            'StartTime' => $allDay ? null : $startdatetime->format('H:i:s'),
                            'EndDate' => $enddatetime->format('Y-m-d'),
                            'EndTime' => $allDay ? null : $enddatetime->format('H:i:s')
                        ]
                    );
                $feedevents->push($feedevent);
            }
        }
        return $feedevents;
    }

    /**
     * @return \DateTime
     */
    public function iCalDateToDateTime($date)
    {
        $dt = new \DateTime($date);
        $dt->setTimeZone(new \DateTimezone($this->config()->timezone));
        return $dt;
    }

    /**
     * @return ArrayList
     */
    public function getAllCalendars()
    {
        $calendars = ArrayList::create();
        $calendars->push($this);
        $calendars->merge($this->NestedCalendars());
        return $calendars;
    }

    public function RegionsWithEvents()
    {
        $list = Region::get();
        $ids = [-1 => 0];
        foreach($list as $region) {
            if($region->HasEvents()) {
                $ids[$region->ID] = $region->ID;
            }
        }
        return $list->filter(['ID' => $ids]);
    }

    /**
     * @return DataList
     */
    public function UpcomingEvents($limit = 5, $filter = null)
    {
        $date = Carbon::now();
        return $this->getEventList(
            $date->toDateString(),
            $date->addMonths($this->DefaultFutureMonths)->toDateString(),
            $filter,
            $limit
        )->limit($limit);
    }

    public function UpcomingAnnouncements($limit = 5, $filter = null)
    {
        $announcements = $this->Announcements()
            ->filter('StartDate:GreaterThan', Carbon::now()->toDateString());
        if ($filter) {
            $announcements = $announcements->where($filter);
        }

        return $announcements->limit($limit);
    }

    /**
     * @return DataList|ArrayList
     */
    public function RecentEvents($limit = null, $filter = null)
    {
        $l = ($limit === null) ? $this->config()->recent_events_default_limit : $limit;
        $events = $this->getEventList(
            Carbon::now()->subMonths($this->DefaultFutureMonths)->toDateString(),
            Carbon::yesterday()->toDateString(),
            $filter
        )->sort(['StartDate' => 'DESC', 'StartTime' => 'DESC']);

        return $events->limit($l);
    }

    /**
     * @return CalendarWidget
     */
    public function getCalendarWidget()
    {
        $calendar = CalendarWidget::create($this);
        $controller = Controller::curr();
        if ($controller instanceof CalendarController) {
            if ($controller->getView() != "default") {
                if ($startDate = $controller->getStartDate()) {
                    $calendar->setOption('start', $startDate->toDateString());
                }
                if ($endDate = $controller->getEndDate()) {
                    $calendar->setOption('end', $endDate->toDateString());
                }
            }
        }
        return $calendar;
    }

    public function getMonthJumpForm()
    {
        $controller = Controller::curr();
        if (!($controller instanceof CalendarController)) {
            $controller = CalendarController::create($this);
        }
        return $controller->MonthJumpForm();
    }
}
