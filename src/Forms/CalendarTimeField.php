<?php

namespace UncleCheese\EventCalendar\Forms;

use SilverStripe\Forms\TimeField;

class CalendarTimeField extends TimeField
{
    public function Field($properties = [])
    {
        $attributes = [
            'type'		=> 'text',
            'class'		=> trim('text ' . $this->extraClass()),
            'id'		=> $this->id(),
            'name'		=> $this->Name(),
            'value'		=> $this->attrValue(),
            'tabindex'	=> $this->getTabIndex(),
            'maxlength'	=> null,
            'size'		=> null
        ];

        if ($this->disabled) {
            $attributes['disabled'] = 'disabled';
        }

        return $this->createTag('input', $attributes);
    }
}
