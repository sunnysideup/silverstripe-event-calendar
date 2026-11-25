<?php

/**
 * Controller for the calendar event page
 *
 * @author Aaron Carlino
 * @author Grant Heggie
 * @package silverstripe-event-calendar
 */

namespace UncleCheese\EventCalendar\Pages;

use Carbon\Carbon;
use SilverStripe\ORM\DataList;
use SilverStripe\View\Requirements;
use UncleCheese\EventCalendar\Pages\Calendar;
use PageController;

class CalendarEventController extends PageController
{
    public function init()
    {
        parent::init();
        if (Calendar::config()->include_default_css) {
            Requirements::css('sunnysideup/silverstripe-event-calendar:client/dist/css/calendar.css');
        }
    }

    /**
     * @return bool
     */
    public function MultipleDates()
    {
        return $this->DateAndTime()->count() > 1;
    }

    /**
     * @return DataList
     */
    public function DateAndTime()
    {
        return DataList::create($this->data()->getDateTimeClass())
            ->filter("EventID", $this->ID)
            ->sort(["StartDate" => "ASC"]);
    }

    /**
     * @return DataList
     */
    public function UpcomingDates($limit = 3)
    {
        return $this->DateAndTime()
            ->filter("StartDate:GreaterThanOrEqual", Carbon::now()->toDateString())
            ->limit($limit);
    }

    /**
     * The (valid) date asked for in the URL, e.g. ?date=2024-03-15
     *
     * @return string|null Y-m-d
     */
    protected function getRequestedDate(): ?string
    {
        $date = $this->getRequest()->getVar('date');
        if (!is_string($date) || $date === '') {
            return null;
        }
        $timestamp = strtotime($date);
        if (!$timestamp || $timestamp < 1) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * @return DataList|false
     */
    public function getOtherDates()
    {
        $date = $this->getRequestedDate();
        if (!$date) {
            $dateObj = $this->DateAndTime()->first();
            if (!$dateObj) {
                return false;
            }
            $date = $dateObj->StartDate;
        }

        $cal = $this->Parent();

        if ($this->Recursion == 1) {
            $datetimeObj = DataList::create($this->data()->getDateTimeClass())
                ->filter('EventID', $this->ID)
                ->first();
            if (!$datetimeObj) {
                return false;
            }
            $datetimeObj->StartDate = $date;
            return $cal->getNextRecurringEvents($this->data(), $datetimeObj);
        }

        return $this->DateAndTime()
            ->exclude("StartDate", $date)
            ->limit($cal->OtherDatesCount);
    }

    /**
     * @return mixed
     */
    public function getCurrentDate()
    {
        $allDates = DataList::create($this->data()->getDateTimeClass())
            ->filter("EventID", $this->ID)
            ->sort(["StartDate" => "ASC"]);
        $date = $this->getRequestedDate();
        if (!$date) {
            // If no date filter specified, return the first one
            return $allDates->first();
        }

        if ($this->Recursion) {
            $datetime = $allDates->first();
            if ($datetime) {
                $datetime->StartDate = $date;
                $datetime->EndDate = $date;
                return $datetime;
            }
        }

        return $allDates->filter("StartDate", $date)->first();
    }
}
