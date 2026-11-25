<?php

namespace UncleCheese\EventCalendar\Helpers;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTime;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use UncleCheese\EventCalendar\Models\CalendarDateTime;
use UncleCheese\EventCalendar\Pages\Calendar;

class CalendarUtil
{
    use Configurable;
    use Injectable;


    public const ONE_DAY = "OneDay";
    public const SAME_MONTH_SAME_YEAR = "SameMonthSameYear";
    public const DIFF_MONTH_SAME_YEAR = "DiffMonthSameYear";
    public const DIFF_MONTH_DIFF_YEAR = "DiffMonthDiffYear";
    public const ONE_DAY_HEADER = "OneDayHeader";
    public const MONTH_HEADER = "MonthHeader";
    public const YEAR_HEADER = "YearHeader";

    /**
     *      You can modify the date display by assigning new date templates to any of the following
     *      date scenarios. Use the above date format keys.
     *
     *      'OneDay' 			    => '$StartMonthNameShort. $StartDayNumberShort, $StartYearLong'
     *      'SameMonthSameYear'     => '$StartMonthNameShort. $StartDayNumberShort - $EndDatNumberShort, $EndYearLong'
     *      'DiffMonthSameYear'     => '$StartMonthNameShort. $StartDayNumberShort - $EndMonthNameShort. $EndDayNumberShort, $EndYearLong'
     *      'DiffMonthDiffYear'     => '$StartMonthNameShort. $StartDayNumberShort, $StartYearLong - $EndMonthNameShort $EndDayNumberShort, $EndYearLong'
     *      'OneDayHeader' 			=> '$StartMonthNameLong $StartDayNumberShort$StartDaySuffix, $StartYearLong'
     *      'MonthHeader' 			=> '$StartMonthNameLong, $StartYearLong'
     *      'YearHeader' 			=> '$StartYearLong'
     *
     * @var array
     */
    private static array $custom_date_templates = [];

    private static $start_end_separator = '—';

    protected static $cache_format_character_replacements = [];
    /**
     * @return array
     */
    public static function format_character_replacements(int $start, int $end): array
    {
        return self::formatCharacterReplacements($start, $end);
    }

    public static function formatCharacterReplacements(int $start, int $end): array
    {
        $key = $start . "-" . $end;
        if(!isset(self::$cache_format_character_replacements[$key])) {
            //if we use the unix timestamp, we get a day earlier in NZ.
            $startDateTime = new DateTime(date('Y-m-d', $start));
            $endDateTime = new DateTime(date('Y-m-d', $end));

            self::$cache_format_character_replacements[$key] = [
                '$StartDayNameShort' => $startDateTime->format('D'), // StartDayNameShort
                '$StartDayNameLong' => $startDateTime->format('l'), // StartDayNameLong
                '$StartDayNumberShort' => $startDateTime->format('j'), // StartDayNumberShort
                '$StartDayNumberLong' => $startDateTime->format('d'), // StartDayNumberLong
                '$StartDaySuffix' => $startDateTime->format('S'), // StartDaySuffix
                '$StartMonthNumberShort' => $startDateTime->format('n'), // StartMonthNumberShort
                '$StartMonthNumberLong' => $startDateTime->format('m'), // StartMonthNumberLong
                '$StartMonthNameShort' => $startDateTime->format('M'), // StartMonthNameShort
                '$StartMonthNameLong' => $startDateTime->format('F'), // StartMonthNameLong
                '$StartYearShort' => $startDateTime->format('y'), // StartYearShort
                '$StartYearLong' => $startDateTime->format('Y'), // StartYearLong
                '$StartEndSeparator' => self::config()->start_end_separator,
                '$EndDayNameShort' => $endDateTime->format('D'), // EndDayNameShort
                '$EndDayNameLong' => $endDateTime->format('l'), // EndDayNameLong
                '$EndDayNumberShort' => $endDateTime->format('j'), // EndDayNumberShort
                '$EndDayNumberLong' => $endDateTime->format('d'), // EndDayNumberLong
                '$EndDaySuffix' => $endDateTime->format('S'), // EndDaySuffix
                '$EndMonthNumberShort' => $endDateTime->format('n'), // EndMonthNumberShort
                '$EndMonthNumberLong' => $endDateTime->format('m'), // EndMonthNumberLong
                '$EndMonthNameShort' => $endDateTime->format('M'), // EndMonthNameShort
                '$EndMonthNameLong' => $endDateTime->format('F'), // EndMonthNameLong
                '$EndYearShort' => $endDateTime->format('y'), // EndYearShort
                '$EndYearLong' => $endDateTime->format('Y'), // EndYearLong
            ];
        }
        return self::$cache_format_character_replacements[$key];
    }


    /**
     * @return string
     */
    public static function localize(int $start, ?int $end, string $key)
    {
        $end ??= $start;
        $customDateTemplates = Config::inst()->get(static::class, 'custom_date_templates');
        if (is_array($customDateTemplates) && isset($customDateTemplates[$key])) {
            $template = $customDateTemplates[$key];
        } else {
            $template = _t(Calendar::class.".$key", $key);
        }
        $replacers = self::format_character_replacements($start, $end);
        return str_replace(
            array_keys($replacers),
            array_values($replacers),
            $template
        );
    }

    /**
     * Turns a URL segment such as 2024, 202403, 2024-03 or 20240315 into a valid Y-m-d date.
     * Anything that is not a valid date results in today's date.
     *
     * @param mixed $str
     * @return string
     */
    public static function get_date_from_string($str)
    {
        $str = str_replace('-', '', (string) $str);
        if (preg_match('/^(\d{4})(\d{2})?(\d{2})?$/', $str, $matches)) {
            $year = (int) $matches[1];
            $month = (int) ($matches[2] ?? 1);
            $day = (int) ($matches[3] ?? 1);
            if ($year >= 1900 && $year <= 2999 && checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        return date('Y-m-d');
    }

    /**
     * Returns the start and end part of a human readable date range
     *
     * @return array
     */
    public static function get_date_string($startDate, $endDate)
    {
        $start = strtotime((string) $startDate);
        $end = strtotime((string) $endDate);

        // Invalid date. Get me out of here!
        if (!$start || $start < 1) {
            return ['', ''];
        }

        $startYear = date("Y", $start);
        $startMonth = date("m", $start);

        // Only one day long!
        if ($start == $end || !$end || $end < 1) {
            $key = self::ONE_DAY;
        } else {
            $endYear = date("Y", $end);
            $endMonth = date("m", $end);
            if ($startYear == $endYear) {
                $key = ($startMonth == $endMonth) ? self::SAME_MONTH_SAME_YEAR : self::DIFF_MONTH_SAME_YEAR;
            } else {
                $key = self::DIFF_MONTH_DIFF_YEAR;
            }
        }
        $dateString = self::localize($start, $end ?: $start, $key);
        $brokenUpIntoStartAndEnd = explode(self::config()->start_end_separator, $dateString);
        if (count($brokenUpIntoStartAndEnd) === 2) {
            return [$brokenUpIntoStartAndEnd[0], $brokenUpIntoStartAndEnd[1]];
        }
        return [$dateString, ""];
    }

    /**
     * Escapes a value for use as an iCalendar TEXT value (RFC 5545 section 3.3.11).
     * This also removes anything that could be used to inject additional iCalendar properties.
     *
     * @param mixed $value
     * @return string
     */
    public static function escape_ics_text($value): string
    {
        $value = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $value) ?? '';
        $value = str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $value);

        return trim($value);
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
        return ($result == "monday") ? CarbonInterface::MONDAY : CarbonInterface::SUNDAY;
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
