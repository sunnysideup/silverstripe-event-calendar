<?php

/**
 * An exception to a recurring event
 *
 * @author Aaron Carlino
 * @author Grant Heggie
 * @package silverstripe-event-calendar
 */

namespace UncleCheese\EventCalendar\Models;

use SilverStripe\Forms\DateField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use UncleCheese\EventCalendar\Helpers\CalendarUtil;
use UncleCheese\EventCalendar\Models\CalendarDateTime;
use UncleCheese\EventCalendar\Pages\CalendarEvent;

class RecurringException extends DataObject
{
    private static $table_name = 'UncleCheese_RecurringException';

    private static $singular_name = 'Recurring exception';

    private static $plural_name = 'Recurring exceptions';

    private static $db = [
        'ExceptionDate' => 'Date'
    ];

    private static $has_one = [
        'CalendarEvent' => CalendarEvent::class
    ];

    private static $default_sort = "ExceptionDate ASC";

    public function getCMSFields()
    {
        //DateField::set_default_config('showcalendar', true);
        $fields = FieldList::create(
            DateField::create('ExceptionDate', _t(__CLASS__.'.EXCEPTIONDATE', 'Exception date'))
        );
        $this->extend('updateCMSFields', $fields);

        return $fields;
    }

    public function summaryFields()
    {
        return [
            'FormattedExceptionDate' => _t(__CLASS__.'.EXCEPTIONDATE', 'Exception date')
        ];
    }

    public function getFormattedExceptionDate()
    {
        if (!$this->ExceptionDate) {
            return CalendarDateTime::config()->formatted_field_empty_string;
        }
        return CalendarUtil::get_date_format() == "mdy"
            ? $this->obj('ExceptionDate')->Format('m-d-Y')
            : $this->obj('ExceptionDate')->Format('d-m-Y');
    }


    public function canCreate($member = null, $context = [])
    {
        return Permission::check("CMS_ACCESS_CMSMain");
    }

    public function canEdit($member = null)
    {
        return Permission::check("CMS_ACCESS_CMSMain");
    }

    public function canDelete($member = null)
    {
        return Permission::check("CMS_ACCESS_CMSMain");
    }

    public function canView($member = null)
    {
        return Permission::check("CMS_ACCESS_CMSMain");
    }
}
