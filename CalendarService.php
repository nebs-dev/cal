<?php

namespace ThirtyMin\Services\Calendar;

use Carbon\Carbon;
use ThirtyMin\Models\User;
use ThirtyMin\Repositories\API\V3\MeetingRepository;

class CalendarService
{
    
    protected $calendar;
    
    
    public function __construct(CalendarInterface $calendar)
    {
        $this->calendar = $calendar;        
    }
    
    
    public function getSuggestedTimes(User $user, array $slots)
    {
        $response = [];
        
        foreach ($slots as $slot) {
            $row = [
                'suggested_time' => $slot,
                'other_events' => $this->getOtherEvents($user, $slot)
            ];
            
            $response[] = $row;
        }
        
        return $response;
    }
    
    
    public function getOtherEvents(User $user, $datetime)
    {
        $service_events = $this->getServiceEvents($datetime);        
        $db_events = $this->getDbEvents($user, $datetime);                
        
        $all_events = array_merge($service_events, $db_events);
                                        
        // Sort events by difference from $datetime
        $all_events = sortEventsByTimeDifference($all_events, $datetime);
        return array_slice($all_events, 0, 3);
    }
        
    
    private function getServiceEvents($datetime)
    {
        $events = [];
        $min_time = Carbon::parse($datetime)->subHours(8)->toW3cString();
        $max_time = Carbon::parse($datetime)->addHours(8)->toW3cString();                                
                                
        foreach ($this->calendar->listEvents() as $service_event) {                                        
            $start = $this->calendar->getStartDatetime($service_event);
            $end = $this->calendar->getEndDatetime($service_event);
            
            if ($start >= $min_time && $end <= $max_time) {                            
                $row['name'] = $service_event->summary;
                $row['starting_at'] = $start;
                $row['ending_at'] = $end;
                
                $events[] = $row;
            }
            
            if (count($events) >= 3) return $events;
        }                        
        
        return $events;
     }
    
    private function getDbEvents(User $user, $datetime)
    {
        $events = [];
        $min_time = Carbon::parse($datetime)->subHours(8)->toW3cString();
        $max_time = Carbon::parse($datetime)->addHours(8)->toW3cString();        
                 
        $db_events = (new MeetingRepository)->getUserAttendMeetings($user, [], $min_time, $max_time)->take(3);                        
        
        foreach ($db_events as $meeting) {
            // $with = ($meeting->created_by == $user->id) ? $meeting->applicant->full_name : $meeting->host->full_name;
            if ($meeting->created_by == $user->id) {
                if ($meeting->applicant) {
                    $meeting_name = 'Meeting with ' . $meeting->applicant->full_name;                
                } else {
                    $meeting_name = $meeting->remote ? 'My remote meeting' : 'My meeting at ' . $meeting->street . ' ' . $meeting->street_number . ', ' . $meeting->city;
                }
            } else {                
                $meeting_name = 'Meeting with ' . $meeting->host->full_name;                                
            }
            
            $row['name'] = $meeting_name;
            $row['starting_at'] = Carbon::parse($meeting->starting_at)->toW3cString();
            $row['ending_at'] = Carbon::parse($meeting->starting_at)->addMinutes(30)->toW3cString();
            
            $events[] = $row;
        }        
        
        return $events;
    }
    
    
    
    
    
    function cmp($a, $b)
    {
        return strcmp($a['starting_at'], $b['starting_at']);
    }
}
