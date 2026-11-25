<?php
/**
 * A simple ICS writer.
 *
 * <h2>Examples</h2>
 *
 * <h3>Send to client</h3>
 *
 * <code>
 * $writer = new ICSWriter($this->data(), Director::absoluteURL('/'));
 * return $writer->sendDownload();
 * </code>
 *
 * <h3>Get output</h3>
 *
 * <code>
 * $writer = new ICSWriter($this->data(), Director::absoluteURL('/'));
 * $writer->getOutput();
 * </code>
 *
 * @todo Support recurring events
 * @copyright 2011 Dimension27
 * @author Alex Hayes <alex.hayes@dimension27.com>
 * @link https://github.com/dimension27/EventCalendar
 */

namespace UncleCheese\EventCalendar\Helpers;

use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injectable;
use UncleCheese\EventCalendar\Models\CalendarDateTime;
use UncleCheese\EventCalendar\Pages\Calendar;

class ICSWriter
{
    use Injectable;

    /**
     * @var Calendar
     */
    private $calendar;

    private $host;
    private $prodid;
    private $limit;

    /**
     * @var array
     */
    private $lines = [];

    /**
     * Construct an ICSWriter instance.
     *
     * @param Calendar $calendar The calendar to render.
     * @param string $host       The calendar host.
     * @param string $prodid     Specifies the identifier for the product that created the iCalendar object. If
     *                           null then $host will be used in the generation of this.
     * @param int $limit         Limit the amount of upcoming events to this number
     *
     * @author Alex Hayes <alex.hayes@dimension27.com>
     */
    public function __construct(Calendar $calendar, $host, $prodid = null, $limit = 100)
    {
        $this->calendar = $calendar;
        $this->host = $host;
        $this->prodid = $prodid;
        $this->limit = $limit;
    }

    /**
     * Get an HTTP response that downloads the calendar. Return it from your controller action.
     *
     * @return HTTPResponse
     */
    public function sendDownload()
    {
        $filename = preg_replace('/[^a-zA-Z0-9]/', '', (string) $this->calendar->Title);
        if ($filename === '') {
            $filename = 'calendar';
        }

        return HTTPResponse::create($this->getOutput())
            ->addHeader('Cache-Control', 'private')
            ->addHeader('Content-Description', 'File Transfer')
            ->addHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->addHeader('Content-Disposition', 'attachment; filename="' . $filename . '.ics"');
    }

    /**
     * Get the calendar as a string.
     *
     * @author Alex Hayes <alex.hayes@dimension27.com>
     */
    public function getOutput()
    {
        $this->lines = [];

        $this->addLine('BEGIN:VCALENDAR');
        $this->addLine('VERSION:2.0');

        if (is_null($this->prodid)) {
            $this->addLine("PRODID:" . '-//' . $this->cleanValue($this->host) . '//NONSGML v1.0//EN');
        } else {
            $this->addLine("PRODID:" . $this->cleanValue($this->prodid));
        }

        $upcomingEvents = $this->calendar->UpcomingEvents($this->limit);

        foreach ($upcomingEvents as $dateTime) {
            $this->addDateTime($dateTime);
        }

        $this->addLine('END:VCALENDAR');

        return implode("\r\n", $this->lines) . "\r\n";
    }

    /**
     * Add a line to the stack.
     *
     * @param string $line
     * @return void
     *
     * @author Alex Hayes <alex.hayes@dimension27.com>
     */
    protected function addLine($line)
    {
        $this->lines[] = $line;
    }

    /**
     * Strip anything that could break out of the current iCalendar line.
     */
    protected function cleanValue($value): string
    {
        return preg_replace('/[\x00-\x1F\x7F]/', '', (string) $value) ?? '';
    }

    /**
     *
     *
     * @param CalendarDateTime $dateTime
     * @return string
     *
     * @author Alex Hayes <alex.hayes@dimension27.com>
     */
    protected function getUID(CalendarDateTime $dateTime)
    {
        $id = is_numeric($dateTime->ID)
            ? $dateTime->ID
            : ($dateTime->DateTimeID ?: $dateTime->ID) . '-' . $dateTime->StartDate;

        return $this->cleanValue($id . '@' . $this->host);
    }

    /**
     * Get an ical formatted datetime string (in UTC).
     *
     * @param string|null $date Y-m-d
     * @param string|null $time H:i:s
     * @return string
     *
     * @todo Add timezone support - note atm there is no timezone support in either Date or Time.
     *
     * @author Alex Hayes <alex.hayes@dimension27.com>
     */
    protected function getFormatedDateTime(?string $date = null, ?string $time = null)
    {
        $timestamp = null;
        if ($date) {
            $timestamp = strtotime($date . ' ' . ($time ?: '00:00:00'));
        }
        if (!$timestamp) {
            $timestamp = time();
        }
        return gmdate('Ymd\THis\Z', $timestamp);
    }

    /**
     * Add a CalendarDateTime to the stack.
     *
     * @param CalendarDateTime $dateTime
     * @return void
     *
     * @author Alex Hayes <alex.hayes@dimension27.com>
     */
    protected function addDateTime(CalendarDateTime $dateTime)
    {
        $startDate = $dateTime->StartDate;
        $endDate = $dateTime->EndDate ?: $startDate;
        $startTime = $dateTime->AllDay ? null : $dateTime->StartTime;
        $endTime = $dateTime->AllDay ? null : ($dateTime->EndTime ?: $dateTime->StartTime);

        $this->addLine('BEGIN:VEVENT');
        $this->addLine('UID:' . $this->getUID($dateTime));
        $this->addLine('DTSTAMP:' . $this->getFormatedDateTime());
        $this->addLine('DTSTART:' . $this->getFormatedDateTime($startDate, $startTime));
        $this->addLine('DTEND:' . $this->getFormatedDateTime($endDate, $endTime));
        $this->addLine('URL:' . $this->cleanValue(Director::absoluteURL($dateTime->ICSLink())));
        $this->addLine('SUMMARY:' . CalendarUtil::escape_ics_text($dateTime->getTitle()));
        $this->addLine('END:VEVENT');
    }
}
