<?php

namespace UncleCheese\EventCalendar\Models;

use SilverStripe\ORM\DataObject;

use UncleCheese\EventCalendar\Pages\Calendar;
use UncleCheese\EventCalendar\Pages\CalendarEvent;

class Region extends DataObject
{
    private static $regions = [];

    private static $table_name = 'Region';

    private static $singular_name = 'Region';

    private static $plural_name = 'Regions';

    private static $db = [
        'Title' => 'Varchar',
    ];

    private static $belongs_many_many = [
        'CalendarEvents' => CalendarEvent::class . '.Regions',
    ];

    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();
        $data = $this->config()->get('regions');
        foreach ((array) $data as $title) {
            $filter = ['Title' => $title];
            if (! Region::get()->filter($filter)->exists()) {
                Region::create($filter)->write();
            }
        }
    }

    protected static $upcoming_events = null;

    protected static function getCalendar(): ?Calendar
    {
        return Calendar::get()->first();
    }

    public function HasEvents(): bool
    {
        if (self::$upcoming_events === null) {
            $calendar = self::getCalendar();
            if (! $calendar) {
                return false;
            }
            self::$upcoming_events = $calendar->UpcomingEvents(9999);
        }
        if (self::$upcoming_events->exists()) {
            $myEvents = $this->CalendarEvents()->columnUnique('ID');
            $allEvents = self::$upcoming_events->columnUnique('EventID');
            return ! empty(array_intersect($myEvents, $allEvents));
        }
        return false;
    }

    public function Link()
    {
        $calendar = self::getCalendar();
        if (! $calendar) {
            return '';
        }
        return $calendar->Link('region/' . rawurlencode((string) $this->Title));
    }
}
