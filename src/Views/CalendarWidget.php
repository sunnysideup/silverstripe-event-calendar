<?php

namespace UncleCheese\EventCalendar\Views;

use SilverStripe\Core\Convert;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\View\Requirements;
use SilverStripe\View\ViewableData;
use UncleCheese\EventCalendar\Pages\Calendar;

class CalendarWidget extends ViewableData
{
    protected $calendar;

    protected $selectionStart;

    protected $selectionEnd;

    protected $options = [];

    public function __construct(Calendar $calendar)
    {
        $this->calendar = $calendar;
    }

    public function setOption($k, $v)
    {
        $this->options[$k] = $v;
    }

    public function getDataAttributes()
    {
        $attributes = "";
        $this->options['url'] = $this->calendar->Link();
        foreach ($this->options as $opt => $value) {
            $attributes .= sprintf('data-%s="%s" ', $opt, Convert::raw2att($value));
        }
        return $attributes;
    }

    public function setSelectionStart($date)
    {
        $this->selectionStart = $date;
        return $this;
    }

    public function setSelectionEnd($date)
    {
        $this->selectionEnd = $date;
        return $this;
    }

    public function forTemplate()
    {
        if (!Calendar::config()->jquery_included) {
            Requirements::javascript('sunnysideup/silverstripe-event-calendar:client/dist/js/jquery.min.js');
        }
        Requirements::javascript("sunnysideup/silverstripe-event-calendar:client/dist/js/calendar_widget.js");

        $localeFile = _t(__CLASS__.'.DATEJSFILE', 'sunnysideup/silverstripe-event-calendar:client/dist/js/lang/calendar_en.js');
        Requirements::javascript($localeFile);

        Requirements::javascript("sunnysideup/silverstripe-event-calendar:client/dist/js/calendar_widget_init.js");
        Requirements::css("sunnysideup/silverstripe-event-calendar:client/dist/css/calendar_widget.css");

        return '<div class="calendar-widget" ' . $this->getDataAttributes() . '></div>';
    }
}
