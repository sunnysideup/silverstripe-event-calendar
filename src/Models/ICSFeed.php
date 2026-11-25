<?php

/**
 * An ICS feed (link and title) attached to a calendar
 *
 * @author Aaron Carlino
 * @author Grant Heggie
 * @package silverstripe-event-calendar
 */

namespace UncleCheese\EventCalendar\Models;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use UncleCheese\EventCalendar\Helpers\ICSFeedReader;
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
            TextField::create('URL', _t(__CLASS__.'.URLLINK', 'URL'), 'https://')
        );
        $this->extend('updateCMSFields', $fields);

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!ICSFeedReader::is_safe_url($this->URL)) {
            $result->addFieldError(
                'URL',
                _t(
                    __CLASS__ . '.INVALIDURL',
                    'Please enter a valid http(s) URL that points to a public website.'
                )
            );
        }

        return $result;
    }

    protected function onBeforeWrite()
    {
        parent::onBeforeWrite();
        $this->URL = ICSFeedReader::normalise_url($this->URL);
    }

    public function summaryFields()
    {
        return [
            'Title' => _t(__CLASS__.'.TITLE', 'Title')
        ];
    }
}
