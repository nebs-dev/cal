<?php

namespace ThirtyMin\Services\Calendar;

interface CalendarInterface
{    
    public function listEvents($optParams = []);    

    public function toDatesArray(array $events);
    
    public function getStartDatetime($event);
    
    public function getEndDatetime($event);
}
