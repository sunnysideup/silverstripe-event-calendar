<?php

namespace UncleCheese\EventCalendar\Helpers;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use UncleCheese\EventCalendar\Pages\CalendarEvent;
use SilverStripe\ORM\DataList;
use SilverStripe\Core\Injector\Injectable;

class RecursionReader
{
    use Injectable;

    public const DAY_SECONDS = 86400;		// Seconds in a day
    public const WEEK_SECONDS = 604800;	// Seconds in a week

    /**
     * @var CalendarEvent
     */
    protected $event;

    /**
     * @var string
     */
    protected $datetimeClass;

    /**
     * @var string
     */
    protected $eventClass;

    /**
     * @var int|null
     */
    protected $ts;

    /**
     * @var array
     */
    protected $allowedDaysOfWeek = [];

    /**
     * @var array
     */
    protected $allowedDaysOfMonth = [];

    /**
     * @var array
     */
    protected $exceptions = [];

    /**
     * @return int
     */
    public static function difference_in_months($dateObj1, $dateObj2)
    {
        return (((int) $dateObj1->format('Y') * 12) + (int) $dateObj1->format('n'))
            - (((int) $dateObj2->format('Y') * 12) + (int) $dateObj2->format('n'));
    }

    /**
     * Number of calendar days between two timestamps (ignores time of day and daylight saving).
     */
    protected static function difference_in_days(int $ts1, int $ts2): int
    {
        $a = new DateTimeImmutable(date('Y-m-d', $ts1));
        $b = new DateTimeImmutable(date('Y-m-d', $ts2));

        return (int) $a->diff($b)->format('%r%a');
    }

    public function __construct(CalendarEvent $event)
    {
        $this->event = $event;
        $cal = $event->Parent();

        $this->datetimeClass = $cal->getDateTimeClass();
        $this->eventClass = $cal->getEventClass();
        $relation = $cal->getDateToEventRelation();

        if ($datetime = DataList::create($this->datetimeClass)
            ->filter($relation, $event->ID)->first()
        ) {
            $ts = strtotime((string) $datetime->StartDate);
            $this->ts = $ts ?: null;
        }

        if ($event->CustomRecursionType == CalendarEvent::RECUR_INTERVAL_WEEKLY) {
            foreach ($event->RecurringDaysOfWeek() as $day) {
                $this->allowedDaysOfWeek[] = (int) $day->Value;
            }
        } elseif ($event->CustomRecursionType == CalendarEvent::RECUR_INTERVAL_MONTHLY) {
            foreach ($event->RecurringDaysOfMonth() as $day) {
                $this->allowedDaysOfMonth[] = (int) $day->Value;
            }
        }

        foreach ($event->Exceptions() as $exception) {
            $this->exceptions[] = $exception->ExceptionDate;
        }
    }

    /**
     * @param int $ts The timestamp to check
     * @return bool
     */
    public function recursionHappensOn($ts)
    {
        if (!$this->ts) {
            return false;
        }

        $ts = (int) $ts;
        $timezone = date_default_timezone_get();
        $testDate = Carbon::createFromTimestamp($ts, $timezone);
        $startDate = Carbon::createFromTimestamp($this->ts, $timezone);

        // Current date is before the recurring event begins.
        if ($testDate->toDateString() < $startDate->toDateString()) {
            return false;
        }
        if (in_array($testDate->toDateString(), $this->exceptions, true)) {
            return false;
        }

        switch ($this->event->CustomRecursionType) {
            case CalendarEvent::RECUR_INTERVAL_DAILY:
                $interval = (int) $this->event->DailyInterval;
                if ($interval < 1) {
                    return false;
                }
                return self::difference_in_days($this->ts, $ts) % $interval === 0;

            case CalendarEvent::RECUR_INTERVAL_WEEKLY:
                $interval = max(1, (int) $this->event->WeeklyInterval);
                $testWeekStart = $testDate->copy()->startOfWeek(CarbonInterface::MONDAY);
                $startWeekStart = $startDate->copy()->startOfWeek(CarbonInterface::MONDAY);
                $weeks = intdiv(self::difference_in_days($startWeekStart->getTimestamp(), $testWeekStart->getTimestamp()), 7);

                return $weeks % $interval === 0
                    && in_array((int) $testDate->format('w'), $this->allowedDaysOfWeek, true);

            case CalendarEvent::RECUR_INTERVAL_MONTHLY:
                $interval = max(1, (int) $this->event->MonthlyInterval);
                if (self::difference_in_months($testDate, $startDate) % $interval !== 0) {
                    return false;
                }
                if ($this->event->MonthlyRecursionType1 == 1) {
                    // A given set of dates in the month e.g. 2 and 15.
                    return in_array((int) $testDate->format('j'), $this->allowedDaysOfMonth, true);
                }
                if ($this->event->MonthlyRecursionType2 == 1) {
                    // e.g. "First Monday of the month"
                    if ((int) $testDate->format('w') !== (int) $this->event->MonthlyDayOfWeek) {
                        return false;
                    }
                    $dayOfMonth = (int) $testDate->format('j');
                    if ((int) $this->event->MonthlyIndex === 5) {
                        // the last one of the month
                        return $dayOfMonth + 7 > (int) $testDate->format('t');
                    }
                    return (int) ceil($dayOfMonth / 7) === (int) $this->event->MonthlyIndex;
                }
                return false;
        }

        return false;
    }
}
