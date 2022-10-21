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

    public function HasEvents() : bool
    {
        $list = DataObject::get_one(Calendar::class)->UpcomingEvents(999999);
        if($list->exists()) {
            return $list->filter(['Regions.ID' => $this->ID])->exists();
        } else {
            return false;
        }
    }

    public function Link()
    {
        return DataObject::get(MemberProfilePage::class)->Link();
    }

}
