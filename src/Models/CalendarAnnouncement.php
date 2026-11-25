<?php

/**
 * A calendar announcement
 *
 * @author Aaron Carlino
 * @author Grant Heggie
 * @package silverstripe-event-calendar
 */

namespace UncleCheese\EventCalendar\Models;

use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use UncleCheese\EventCalendar\Pages\Calendar;
use UncleCheese\EventCalendar\Models\CalendarDateTime;

class CalendarAnnouncement extends CalendarDateTime
{
    private static $table_name = 'UncleCheese_CalendarAnnouncement';

    private static $singular_name = 'Calendar announcement';

    private static $plural_name = 'Calendar announcements';

    private static $db = [
        'Title' => 'Varchar(255)',
        'Content' => 'Text'
    ];

    private static $has_one = [
        'Calendar' => Calendar::class
    ];

    public function getCMSFields()
    {
        $this->beforeUpdateCMSFields(function ($f) {
            $f->insertBefore(
                'StartDate',
                TextField::create('Title', _t(__CLASS__.'.TITLE', 'Title of announcement'))
            );

            $f->insertBefore(
                'StartDate',
                TextareaField::create('Content', _t(__CLASS__.'.CONTENT', 'Announcement content'))
            );
        });

        return parent::getCMSFields();
    }

    public function summaryFields()
    {
        return [
            'Title' => _t(__CLASS__.'.TITLE', 'Title of announcement'),
            'FormattedStartDate' => _t(Calendar::class.'.STARTDATE', 'Start date'),
            'FormattedEndDate' => _t(Calendar::class.'.ENDDATE', 'End date'),
            'FormattedStartTime' => _t(Calendar::class.'.STARTTIME', 'Start time'),
            'FormattedEndTime' => _t(Calendar::class.'.ENDTIME', 'End time'),
            'FormattedAllDay' => _t(Calendar::class.'.ALLDAY', 'All day'),
        ];
    }

    /**
     * Announcements do not have a detail page, so link to the calendar they are in.
     *
     * @return string
     */
    public function Link()
    {
        return $this->Calendar()->Link();
    }

    public function getTitle()
    {
        return $this->getField('Title');
    }

    public function getContent()
    {
        return $this->getField('Content');
    }
}
