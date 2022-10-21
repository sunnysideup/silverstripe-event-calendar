<?php

namespace UncleCheese\EventCalendar\Models;
use SilverStripe\ORM\DataObject;

class Region extends DataObject
{

    private static $regions = [];

    private static $table_name = 'Region';

    private static $singular_name = 'Region';

    private static $plural_name = 'Regions';

    private static $db = [
        'Title' => 'Varchar',
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

}
