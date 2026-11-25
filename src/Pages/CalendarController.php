<?php

/**
 * Controller for the calendar page
 *
 * @author Aaron Carlino
 * @author Grant Heggie
 * @package silverstripe-event-calendar
 */

namespace UncleCheese\EventCalendar\Pages;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use UncleCheese\EventCalendar\Helpers\CalendarUtil;
use UncleCheese\EventCalendar\Helpers\ICSWriter;
use UncleCheese\EventCalendar\Models\Region;
use Error;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\HTTP;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\RSS\RSSFeed;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;

use SilverStripe\ORM\DataObject;
use SilverStripe\View\Requirements;
use PageController;

class CalendarController extends PageController
{
    private static $allowed_actions = [
        'show',
        'region',
        'month',
        'rss',
        'today',
        'week',
        'weekend',
        'ical',
        'ics',
        'monthjson',
        'MonthJumpForm'
    ];

    /**
     * @var string
     */
    private static $default_organiser;

    /**
     * The most events that can be requested in one go (e.g. by the monthjson action)
     *
     * @var int
     */
    private static $max_events_per_page = 500;

    protected $view;

    /**
     * @var Carbon
     */
    protected $startDate;

    /**
     * @var Carbon
     */
    protected $endDate;

    /**
     * @var Region
     */
    protected $region;

    /**
     * @var int|null
     */
    protected $eventsPerPageOverride;

    public function init()
    {
        parent::init();
        if (Calendar::config()->include_rss_link) {
            RSSFeed::linkToFeed($this->Link() . "rss", $this->RSSTitle ? $this->RSSTitle : $this->Title);
        }
        if (Calendar::config()->include_default_css) {
            Requirements::css('sunnysideup/silverstripe-event-calendar:client/dist/css/calendar.css');
        }
        if (Calendar::config()->include_calendar_js) {
            if (!Calendar::config()->jquery_included) {
                Requirements::javascript('sunnysideup/silverstripe-event-calendar:client/dist/js/jquery.min.js');
            }
            Requirements::javascript('sunnysideup/silverstripe-event-calendar:client/dist/js/calendar.js');
        }
    }

    public function index(HTTPRequest $r)
    {
        $this->extend('index', $r);

        switch ($this->DefaultView) {
            case "month":
                $this->setMonthView();
                return $this->respond();
            case "week":
                $this->setWeekView();
                // prevent pagination on these default views
                $this->eventsPerPageOverride = 999;
                $e = $this->getEvents();
                if ($e->count() > 0) {
                    return ['Events' => $e];
                }
                $this->setMonthView();
                return [];
            case "today":
                // prevent pagination on these default views
                $this->eventsPerPageOverride = 999;
                $this->setTodayView();
                $e = $this->getEvents();
                if ($e->count() > 0) {
                    return ['Events' => $e];
                }
                $this->setWeekView();
                return [];
            default:
                $this->setDefaultView();
                return $this->respond();
        }
    }

    public function today(HTTPRequest $r)
    {
        $this->setTodayView();
        return $this->respond();
    }

    public function week(HTTPRequest $r)
    {
        $this->setWeekView();
        return $this->respond();
    }

    public function weekend(HTTPRequest $r)
    {
        $this->setWeekendView();
        return $this->respond();
    }

    public function month(HTTPRequest $r)
    {
        $this->setMonthView();
        return $this->respond();
    }

    public function region(HTTPRequest $r)
    {
        $regionTitle = rawurldecode((string) $r->param('ID'));
        $this->region = Region::get()->filter(['Title' => $regionTitle])->first();
        if (!$this->region) {
            return $this->httpError(404);
        }
        $this->setRegionView();
        return $this->respond();
    }

    public function show(HTTPRequest $r)
    {
        $this->parseURL($r);
        return $this->respond();
    }

    public function rss()
    {
        $this->setDefaultView();
        $events = $this->getEvents();
        $rssTitle = $this->RSSTitle
            ? $this->RSSTitle
            : sprintf(
                _t(Calendar::class.'.UPCOMINGEVENTSFOR', "Upcoming Events for %s"),
                $this->Title
            );
        $rss = RSSFeed::create($events, $this->Link(), $rssTitle, "", "RSSItemTitle", "RSSItemDescription");
        return $rss->outputToBrowser();
    }

    /**
     * @return HTTPResponse
     */
    public function monthjson(HTTPRequest $r)
    {
        $json = [];
        if ($r->param('ID')) {
            // the AJAX request won't look for further pages
            $this->eventsPerPageOverride = (int) $this->config()->max_events_per_page;
            $this->startDate = Carbon::parse(
                CalendarUtil::get_date_from_string($r->param('ID'))
            )->startOfMonth();
            $this->endDate = $this->startDate->copy()->endOfMonth();

            $counter = $this->startDate->copy();
            while ($counter->getTimestamp() <= $this->endDate->getTimestamp()) {
                $json[$counter->toDateString()] = [
                    'events' => []
                ];
                $counter->addDay();
            }
            foreach ($this->getEvents() as $e) {
                foreach ($e->getAllDatesInRange() as $date) {
                    if (isset($json[$date])) {
                        $json[$date]['events'][] = $e->getTitle();
                    }
                }
            }
        }

        return HTTPResponse::create(json_encode($json, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT))
            ->addHeader('Content-Type', 'application/json; charset=utf-8')
            ->addHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * @return Carbon
     */
    public function getStartDate()
    {
        return $this->startDate;
    }

    /**
     * @return Carbon
     */
    public function getEndDate()
    {
        return $this->endDate;
    }

    public function getView()
    {
        return $this->view;
    }

    /**
     * @return int
     */
    public function getEventsPerPage()
    {
        if ($this->eventsPerPageOverride) {
            return $this->eventsPerPageOverride;
        }
        $perPage = (int) $this->data()->EventsPerPage;

        return $perPage > 0 ? $perPage : 10;
    }

    public function setDefaultView()
    {
        $this->view = 'default';
        $this->startDate = Carbon::now();
        $this->endDate = Carbon::now()->addMonths($this->DefaultFutureMonths);
    }

    public function setRegionView()
    {
        $this->view = 'region';
        $this->startDate = Carbon::now();
        $this->endDate = Carbon::now()->addMonths($this->DefaultFutureMonths);
    }

    public function setTodayView()
    {
        $this->view = 'day';
        $this->startDate = Carbon::now();
        $this->endDate = Carbon::now();
    }

    public function setWeekView()
    {
        $this->view = 'week';
        if (CalendarUtil::get_first_day_of_week() == CarbonInterface::MONDAY) {
            $this->startDate = Carbon::now()->startOfWeek(CarbonInterface::MONDAY);
            $this->endDate = Carbon::now()->endOfWeek(CarbonInterface::SUNDAY);
        } else {
            $this->startDate = Carbon::now()->startOfWeek(CarbonInterface::SUNDAY);
            $this->endDate = Carbon::now()->endOfWeek(CarbonInterface::SATURDAY);
        }
    }

    public function setWeekendView()
    {
        $this->view = 'weekend';
        $start = Carbon::now();
        if ($start->dayOfWeek == CarbonInterface::SATURDAY) {
            $start = $start->subDay();
        } elseif ($start->dayOfWeek == CarbonInterface::SUNDAY) {
            $start = $start->subDays(2);
        } elseif ($start->dayOfWeek != CarbonInterface::FRIDAY) {
            $start = $start->next(CarbonInterface::FRIDAY);
        }
        $this->startDate = $start;
        $this->endDate = $this->startDate->copy()->next(CarbonInterface::SUNDAY);
    }

    public function setMonthView()
    {
        $this->view = 'month';
        $this->startDate = Carbon::now()->startOfMonth();
        $this->endDate = $this->startDate->copy()->endOfMonth();
    }

    /**
     * @return int
     */
    public function getOffset()
    {
        return max(0, (int) $this->getRequest()->getVar('start'));
    }

    protected function getRangeLink($start, $end)
    {
        return parent::join_links(
            $this->Link(),
            "show",
            $start->format('Ymd'),
            $end->format('Ymd')
        );
    }

    public function respond()
    {
        if ($this->getRequest()->isAjax()) {
            return $this->renderWith('UncleCheese\EventCalendar\Includes\EventList');
        }
        return [];
    }

    /**
     * Send ical file of multiple upcoming events using ICSWriter
     *
     * @todo Support recurring events.
     * @see ICSWriter
     * @author Alex Hayes <alex.hayes@dimension27.com>
     * @return HTTPResponse
     */
    public function ical()
    {
        $host = (string) parse_url(Director::absoluteBaseURL(), PHP_URL_HOST);
        $writer = ICSWriter::create($this->data(), $host);
        return $writer->sendDownload();
    }

    /**
     * Download a single event as an ICS file
     *
     * @return HTTPResponse
     */
    public function ics(HTTPRequest $r)
    {
        $feed = false;
        $announcement = false;
        $id = (string) $r->param('ID');
        $oid = (string) $r->param('OtherID');

        if (stripos($id, "ICS_") === 0) {
            $id = substr($id, 4);
            $feed = true;
        } elseif (stripos($id, "announcement-") === 0) {
            $id = substr($id, 13);
            $announcement = true;
        }

        $timestampPattern = '\d{8}T\d{4}(?:\d{2})?';
        if (!ctype_digit($id) || !preg_match('/^(' . $timestampPattern . ')-(' . $timestampPattern . ')$/', $oid, $parts)) {
            return $this->httpError(404);
        }
        $startTimestamp = $parts[1];
        $endTimestamp = $parts[2];

        $calendar = $this->data();
        $event = null;
        if ($feed) {
            if (!$calendar->Feeds()->byID((int) $id)) {
                return $this->httpError(404);
            }
            $filename = $r->getVar('title');
            $title = $r->getVar('title');
            $content = $r->getVar('content');
            $location = $r->getVar('location');
            $url = '';
        } else {
            if ($announcement) {
                $event = DataObject::get($calendar->getAnnouncementClass())
                    ->filter('CalendarID', $calendar->ID)
                    ->byID((int) $id);
            } else {
                $event = DataObject::get($calendar->getEventClass())->byID((int) $id);
                if ($event && !$event->canView()) {
                    $event = null;
                }
            }
            if (!$event) {
                return $this->httpError(404);
            }
            $filename = $announcement ? $event->Title : $event->URLSegment;
            $title = $event->Title;
            $content = $announcement ? $event->Content : $event->obj('Content')->Summary();
            $location = $announcement ? '' : $event->Location;
            $url = $announcement ? $event->Calendar()->AbsoluteLink() : $event->AbsoluteLink();
        }

        $filename = preg_replace('/[^a-zA-Z0-9]/', '', mb_substr((string) $filename, 0, 100));
        if ($filename === '') {
            $filename = 'event';
        }

        $host = (string) parse_url(Director::absoluteBaseURL(), PHP_URL_HOST);
        $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $host);

        $result = $this->customise(
            [
                'HOST' => $host,
                'LANGUAGE' => preg_replace('/[^a-zA-Z_\-]/', '', (string) Calendar::config()->language),
                'TIMEZONE' => preg_replace('/[^a-zA-Z0-9_\/+\-]/', '', (string) Calendar::config()->timezone),
                'UID' => sprintf('%s-%s@%s', $id, $oid, $host),
                'DTSTAMP' => gmdate("Ymd\THis\Z"),
                'START_TIMESTAMP' => $startTimestamp,
                'END_TIMESTAMP' => $endTimestamp,
                'URL' => preg_replace('/[\x00-\x20\x7F]/', '', (string) $url),
                'TITLE' => CalendarUtil::escape_ics_text(mb_substr((string) $title, 0, 255)),
                'CONTENT' => CalendarUtil::escape_ics_text(mb_substr((string) $content, 0, 1000)),
                'LOCATION' => CalendarUtil::escape_ics_text(mb_substr((string) $location, 0, 255)),
                'ORGANIZER' => preg_replace('/[\x00-\x1F\x7F]/', '', (string) $this->getOrganiser())
            ]
        )->renderWith(['UncleCheese\EventCalendar\ics']);

        $body = preg_replace("/\r\n|\r|\n/", "\r\n", trim((string) $result)) . "\r\n";

        return HTTPResponse::create($body)
            ->addHeader('Cache-Control', 'private')
            ->addHeader('Content-Description', 'File Transfer')
            ->addHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->addHeader('Content-Disposition', 'attachment; filename="' . $filename . '.ics"');
    }

    /**
     * @return void
     */
    public function parseURL(HTTPRequest $r)
    {
        if (!$r->param('ID')) {
            return;
        }
        $this->startDate = Carbon::parse(CalendarUtil::get_date_from_string($r->param('ID')));
        $d = $this->startDate->copy();
        if ($r->param('OtherID')) {
            $this->view = "range";
            $this->endDate = Carbon::parse(CalendarUtil::get_date_from_string($r->param('OtherID')));
            if ($this->endDate < $this->startDate) {
                $this->endDate = $this->startDate->copy();
            }
            $latest = $this->startDate->copy()->addDays(max(1, (int) Calendar::config()->max_date_range_days));
            if ($this->endDate > $latest) {
                $this->endDate = $latest;
            }
        } elseif ($r->param('Action') === 'region') {
            $this->view = "region";
            $this->endDate = Carbon::parse($d->addMonths($this->DefaultFutureMonths)->toDateString());
        } else {
            switch (strlen(str_replace("-", "", (string) $r->param('ID')))) {
                case 8:
                    $this->view = "day";
                    $this->endDate = $d->copy()->endOfDay();
                    break;

                case 6:
                    $this->view = "month";
                    $this->endDate = Carbon::parse($d->endOfMonth()->toDateString());
                    break;

                case 4:
                    $this->view = "year";
                    $this->endDate = Carbon::parse($d->endOfYear()->toDateString());
                    break;

                default:
                    $this->view = "default";
                    $this->endDate = Carbon::parse($d->addMonths($this->DefaultFutureMonths)->toDateString());
                    break;
            }
        }
    }

    public function getEvents()
    {
        $eventFilter = null;
        $announcementFilter = null;
        $endDate = $this->endDate;

        $search = $this->getRequest()->getVar('s');
        if (is_string($search) && trim($search) !== '') {
            $search = mb_substr(trim($search), 0, 100);
            $like = '%' . $search . '%';
            $siteTreeTable = SiteTree::config()->table_name;
            $schema = DataObject::getSchema();
            $announcementClass = $this->data()->getAnnouncementClass();
            $announcementTitle = '"' . $schema->tableForField($announcementClass, 'Title') . '"."Title"';
            $announcementContent = '"' . $schema->tableForField($announcementClass, 'Content') . '"."Content"';
            $eventFilter = [
                "\"$siteTreeTable\".\"Title\" LIKE ? OR \"$siteTreeTable\".\"Content\" LIKE ?" => [$like, $like]
            ];
            $announcementFilter = [
                "$announcementTitle LIKE ? OR $announcementContent LIKE ?" => [$like, $like]
            ];
            $this->SearchQuery = $search;
            $endDate = Carbon::now()->addMonths($this->DefaultFutureMonths);
        }

        $all = $this->data()->getEventList(
            $this->startDate ? $this->startDate->toDateString() : null,
            $endDate ? $endDate->toDateString() : null,
            $eventFilter,
            null,
            $announcementFilter
        );

        if ($this->region) {
            $eventIds = $this->region->CalendarEvents()->columnUnique('ID') ?: [-1];
            $all = $all->filter(['EventID' => $eventIds]);
        }

        $perPage = $this->getEventsPerPage();
        $allEventsCount = $all->count();
        $list = $all->limit($perPage, $this->getOffset());
        $next = $this->getOffset() + $perPage;
        $this->MoreEvents = ($next < $allEventsCount);
        $this->MoreLink = HTTP::setGetVar("start", $next);

        return $list;
    }

    /**
     * @return string
     */
    public function DateHeader()
    {
        switch ($this->view) {
            case 'region':
                return $this->region ? $this->region->Title : $this->DefaultDateHeader;
            case "day":
                return CalendarUtil::localize(
                    $this->startDate->getTimestamp(),
                    null,
                    CalendarUtil::ONE_DAY_HEADER
                );
            case "month":
                return CalendarUtil::localize(
                    $this->startDate->getTimestamp(),
                    null,
                    CalendarUtil::MONTH_HEADER
                );
            case "year":
                return CalendarUtil::localize(
                    $this->startDate->getTimestamp(),
                    null,
                    CalendarUtil::YEAR_HEADER
                );
            case "range":
            case "week":
            case "weekend":
                [$strStartDate, $strEndDate] = CalendarUtil::get_date_string(
                    $this->startDate->toDateString(),
                    $this->endDate->toDateString()
                );
                return $strEndDate
                    ? $strStartDate . CalendarUtil::config()->get('start_end_separator') . $strEndDate
                    : $strStartDate;

            default:
                return $this->DefaultDateHeader;
        }
    }

    /**
     * @return bool
     */
    public function CurrentAction($a)
    {
        return $this->getAction() == $a;
    }

    /**
     * @return string
     */
    public function PreviousDayLink()
    {
        $start = Carbon::parse($this->startDate)->subDay();
        return $this->getRangeLink($start, $start);
    }

    /**
     * @return string
     */
    public function NextDayLink()
    {
        $start = Carbon::parse($this->startDate)->addDay();
        return $this->getRangeLink($start, $start);
    }

    /**
     * @return string
     */
    public function PreviousWeekLink()
    {
        $start = Carbon::parse($this->startDate)->subWeek();
        $end = Carbon::parse($this->endDate)->subWeek();
        return $this->getRangeLink($start, $end);
    }

    /**
     * @return string
     */
    public function NextWeekLink()
    {
        $start = Carbon::parse($this->startDate)->addWeek();
        $end = Carbon::parse($this->endDate)->addWeek();
        return $this->getRangeLink($start, $end);
    }

    /**
     * @return string
     */
    public function NextMonthLink()
    {
        $start = Carbon::parse($this->startDate)->startOfMonth()->addMonth();
        $end = $start->copy()->endOfMonth();
        return $this->getRangeLink($start, $end);
    }

    /**
     * @return string
     */
    public function PreviousMonthLink()
    {
        $start = Carbon::parse($this->startDate)->startOfMonth()->subMonth();
        $end = $start->copy()->endOfMonth();
        return $this->getRangeLink($start, $end);
    }

    /**
     * @return string
     */
    public function NextWeekendLink()
    {
        return $this->NextWeekLink();
    }

    /**
     * @return string
     */
    public function PreviousWeekendLink()
    {
        return $this->PreviousWeekLink();
    }

    /**
     * @return bool
     */
    public function IsSegment($segment)
    {
        if (!$this->startDate || !$this->endDate) {
            return false;
        }
        switch ($segment) {
            case "today":
                return $this->startDate->toDateString() == $this->endDate->toDateString();
            case "week":
                if (CalendarUtil::get_first_day_of_week() == CarbonInterface::MONDAY) {
                    return
                        ($this->startDate->dayOfWeek == CarbonInterface::MONDAY)
                        && ($this->endDate->dayOfWeek == CarbonInterface::SUNDAY);
                }
                return
                    ($this->startDate->dayOfWeek == CarbonInterface::SUNDAY)
                    && ($this->endDate->dayOfWeek == CarbonInterface::SATURDAY);
            case "month":
                return
                    ($this->startDate->day == 1)
                    && ($this->startDate->copy()->endOfMonth()->day == $this->endDate->day);
            case "weekend":
                return
                    ($this->startDate->dayOfWeek == CarbonInterface::FRIDAY)
                    && ($this->endDate->dayOfWeek == CarbonInterface::SUNDAY);
        }
        return false;
    }

    /**
     * @return string
     */
    public function MonthJumper()
    {
        return $this->renderWith('UncleCheese\EventCalendar\Includes\MonthJumper');
    }

    /**
     * @return Form
     */
    public function MonthJumpForm()
    {
        try {
            $request = $this->getRequest();
        } catch (Error $e) {
            $request = Director::currentRequest() ?: HTTPRequest::create('GET', '/');
        }
        $this->parseURL($request);
        $dummy = $this->startDate ? $this->startDate->copy() : Carbon::now();
        $yearRange = range((int) $dummy->copy()->subYears(3)->format('Y'), (int) $dummy->copy()->addYears(3)->format('Y'));
        $form = Form::create(
            $this,
            __FUNCTION__,
            FieldList::create(
                $m = DropdownField::create('Month', '', CalendarUtil::getMonthsMap('F')),
                $y = DropdownField::create('Year', '', array_combine($yearRange, $yearRange))
            ),
            FieldList::create(
                FormAction::create('doMonthJump', _t(__CLASS__.'.JUMP', 'Go'))
            )
        );

        $m->setValue($dummy->format('m'));
        $y->setValue($dummy->format('Y'));

        return $form;
    }

    public function doMonthJump($data, $form)
    {
        $year = (int) ($data['Year'] ?? 0);
        $month = (int) ($data['Month'] ?? 0);
        if ($year < 1900 || $year > 2999 || $month < 1 || $month > 12) {
            return $this->redirectBack();
        }

        return $this->redirect(
            parent::join_links(
                $this->Link('show'),
                sprintf('%04d%02d', $year, $month)
            )
        );
    }

    public function getOrganiser()
    {
        $organiser = $this->config()->default_organiser;
        if (!$organiser) {
            $email = Email::config()->get('admin_email');
            if (is_array($email)) {
                $email = array_key_first($email);
            }
            $organiser = $email ? ":MAILTO:" . $email : '';
        }

        $this->extend('updateOrganiser', $organiser);
        return $organiser;
    }
}
