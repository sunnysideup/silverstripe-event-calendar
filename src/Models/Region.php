<?php

namespace UncleCheese\EventCalendar\Models;
use SilverStripe\ORM\DataObject;

use Symbiote\MemberProfiles\Pages\MemberProfilePage;

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
        'CalendarEvents' => CalendarEvent::class,
    ];

    function requireDefaultRecords()
    {
        $data = $this->Config()->get('regions');
        foreach($data as $title) {
            $filter = ['Title' => $title];
            if(! Region::get()->filter($filter)->exists()) {
                Region::create($filter)->write();
            }
        }
    }

    protected static $upcoming_events = null;

    public function HasEvents() : bool
    {
        if(self::$upcoming_events === null) {
            self::$upcoming_events = DataObject::get_one(Calendar::class)->UpcomingEvents(9999);
        }
        if(self::$upcoming_events->exists()) {
            $myEvents = $this->CalendarEvents()->columnUnique('ID');
            $allEvents = self::$upcoming_events->columnUnique('EventID');
            return ! empty(array_intersect($myEvents, $allEvents));
        }
        return false;
    }

    public function Link()
    {
        return DataObject::get_one(Calendar::class)->Link('region/'.$this->Title);
    }

}
