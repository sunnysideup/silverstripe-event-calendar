<?php

/**
 * An ICS feed (link and title) attached to a calendar
 *
 * @author Aaron Carlino
 * @author Grant Heggie
 * @package silverstripe-event-calendar
 */

namespace UncleCheese\EventCalendar\Models;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use UncleCheese\EventCalendar\Pages\Calendar;

class ICSFeed extends DataObject
{
    private static $table_name = 'UncleCheese_ICSFeed';

    private static $db = [
        'Title' => 'Varchar(100)',
        'URL' => 'Varchar(255)'
    ];

    private static $has_one = [
        'Calendar' => Calendar::class
    ];

    public function getCMSFields()
    {
        $fields = FieldList::create(
            TextField::create('Title', _t(__CLASS__.'.TITLEOFFEED', 'Title of feed')),
            TextField::create('URL', _t(__CLASS__.'.URLLINK', 'URL'), 'http://')
        );


/**
  * ### @@@@ START REPLACEMENT @@@@ ###
  * WHY: automated upgrade
  * OLD: ->extend(
  * NEW: ->extend( ...  (COMPLEX)
  * EXP: Changed parameter name in ... SilverStripe\Core\Extensible::extend() from $a1 to $arguments and modified it to accept variable arguments using the splat operator
  * ### @@@@ STOP REPLACEMENT @@@@ ###
  */
        $this->extend('updateCMSFields', $fields);

        return $fields;
    }
    
    public function summaryFields()
    {
        return [
            'Title' => _t(__CLASS__.'.TITLE', 'Title')
        ];
    }
}
