<?php

namespace UncleCheese\EventCalendar\Helpers;

use Carbon\Carbon;
use DateTime;
use UncleCheese\EventCalendar\Models\CalendarDateTime;
use UncleCheese\EventCalendar\Pages\Calendar;

class CalendarUtil
{
    public const ONE_DAY = "OneDay";
    public const SAME_MONTH_SAME_YEAR = "SameMonthSameYear";
    public const DIFF_MONTH_SAME_YEAR = "DiffMonthSameYear";
    public const DIFF_MONTH_DIFF_YEAR = "DiffMonthDiffYear";
    public const ONE_DAY_HEADER = "OneDayHeader";
    public const MONTH_HEADER = "MonthHeader";
    public const YEAR_HEADER = "YearHeader";

    /**
     * @return array
     */
    private static $format_character_placeholders = [
        '$StartDayNameShort',
        '$StartDayNameLong',
        '$StartDayNumberShort',
        '$StartDayNumberLong',
        '$StartDaySuffix',
        '$StartMonthNumberShort',
        '$StartMonthNumberLong',
        '$StartMonthNameShort',
        '$StartMonthNameLong',
        '$StartYearShort',
        '$StartYearLong',
        '$EndDayNameShort',
        '$EndDayNameLong',
        '$EndDayNumberShort',
        '$EndDayNumberLong',
        '$EndDaySuffix',
        '$EndMonthNumberShort',
        '$EndMonthNumberLong',
        '$EndMonthNameShort',
        '$EndMonthNameLong',
        '$EndYearShort',
        '$EndYearLong'
    ];

    /**
     * @return array
     */
    public static function formatCharacterReplacements(int $start, int $end): array
    {
        $startDateTime = new DateTime('@' . $start);
        $endDateTime = new DateTime('@' . $end);

        return [
            $startDateTime->format('D'),
            $startDateTime->format('l'),
            $startDateTime->format('j'),
            $startDateTime->format('d'),
            $startDateTime->format('S'),
            $startDateTime->format('n'),
            $startDateTime->format('m'),
            $startDateTime->format('M'),
            $startDateTime->format('F'),
            $startDateTime->format('y'),
            $startDateTime->format('Y'),
            $endDateTime->format('D'),
            $endDateTime->format('l'),
            $endDateTime->format('j'),
            $endDateTime->format('d'),
            $endDateTime->format('S'),
            $endDateTime->format('n'),
            $endDateTime->format('m'),
            $endDateTime->format('M'),
            $endDateTime->format('F'),
            $endDateTime->format('y'),
            $endDateTime->format('Y'),
        ];
    }


    /**
     * @return string
     */
    public static function localize($start, $end, $key)
    {
        global $customDateTemplates;
        if (is_array($customDateTemplates) && isset($customDateTemplates[$key])) {
            $template = $customDateTemplates[$key];
        } else {
            $template = _t(Calendar::class.".$key", $key);
        }

        return str_replace(
            self::$format_character_placeholders,
            self::format_character_replacements($start, $end),
            $template
        );
    }

    /**
     * @return string
     */
    public static function get_date_from_string($str)
    {
        $str = str_replace('-', '', $str);
        if (is_numeric($str)) {
            $missing = (8 - strlen((string) $str));
            if ($missing > 0) {
                while ($missing > 0) {
                    $str .= "01";
                    $missing -= 2;
                }
            }
            return substr((string) $str, 0, 4) . "-" . substr((string) $str, 4, 2) . "-" . substr((string) $str, 6, 2);
        }

        return date('Y-m-d');
    }

    /**
     * @return array|null
     */
    public static function get_date_string($startDate, $endDate)
    {
        $strStartDate = null;
        $strEndDate = null;
        $start = strtotime((string) $startDate);
        $end = strtotime((string) $endDate);

        $startYear = date("Y", $start);
        $startMonth = date("m", $start);

        $endYear = date("Y", $end);
        $endMonth = date("m", $end);

        // Invalid date. Get me out of here!
        if ($start < 1) {
            return;
        }

        // Only one day long!
        if ($start == $end || !$end || $end < 1) {
            $key = self::ONE_DAY;
        } elseif ($startYear == $endYear) {
            $key = ($startMonth == $endMonth) ? self::SAME_MONTH_SAME_YEAR : self::DIFF_MONTH_SAME_YEAR;
        } else {
            $key = self::DIFF_MONTH_DIFF_YEAR;
        }
        $dateString = self::localize($start, $end, $key);

        $break = strpos((string) $dateString, '$End');
        if ($break !== false) {

            $strStartDate = substr((string) $dateString, 0, $break);
            $strEndDate = substr((string) $dateString, $break + 1, strlen((string) $dateString) - strlen((string) $strStartDate));
            return [$strStartDate, $strEndDate];
        }

        return [$dateString, ""];
    }

    /**
     * @return string
     */
    public static function microformat($date, $time, $offset = null)
    {
        if (!$date) {
            return "";
        }

        $ts = strtotime((string) $date . " " . $time);
        if ($ts < 1) {
            return "";
        }
        $ret = date('c', $ts); // ISO 8601 datetime
        if ($offset) {
            // Swap out timezine with specified $offset
            $ret = preg_replace('/((\+)|(-))[\d:]*$/', $offset, $ret);
        }
        return $ret;
    }

    /**
     * @return array
     */
    public static function getMonthsMap(string $key = 'M'): array
    {
        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $dateTime = new DateTime("2000-$month-01");
            $formattedMonth = str_pad($month, 2, '0', STR_PAD_LEFT);
            $months[$formattedMonth] = $dateTime->format($key);
        }

        return $months;
    }

    /**
     * @return string
     */
    public static function get_date_format()
    {
        if ($dateFormat = CalendarDateTime::config()->date_format_override) {
            return $dateFormat;
        }
        return _t(__CLASS__.'.DATEFORMAT', 'mdy');
    }

    /**
     * @return string
     */
    public static function get_time_format()
    {
        if ($timeFormat = CalendarDateTime::config()->time_format_override) {
            return $timeFormat;
        }
        return _t(__CLASS__.'.TIMEFORMAT', '24');
    }

    /**
     * @return int
     */
    public static function get_first_day_of_week()
    {
        $result = strtolower(_t(__CLASS__.'.FIRSTDAYOFWEEK', 'monday'));
        return ($result == "monday") ? Carbon::MONDAY : Carbon::SUNDAY;
    }

    public static function date_sort(&$data)
    {
        uasort($data, [self::class, "date_sort_callback"]);
    }

    /**
     * Callback used by column_sort
     */
    public static function date_sort_callback($a, $b)
    {
        if ($a->StartDate == $b->StartDate) {
            if ($a->StartTime == $b->StartTime) {
                return 0;
            } elseif (strtotime((string) $a->StartTime) > strtotime((string) $b->StartTime)) {
                return 1;
            }
            return -1;
        } elseif (strtotime((string) $a->StartDate) > strtotime((string) $b->StartDate)) {
            return 1;
        }
        return -1;
    }

    /**
     * @return string
     */
    public static function format_time($timeObj)
    {
        return self::get_time_format() == '24'
            ? $timeObj->Format('HH:mm')
            : $timeObj->Nice();
    }
}
