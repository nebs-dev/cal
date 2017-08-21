<?php

namespace ThirtyMin\Services\Calendar;

use ThirtyMin\Models\User;
use ThirtyMin\Repositories\API\V3\MeetingRepository;
use Carbon\Carbon;

class CalendarCalculator
{
    private $applicantCalendar;
    private $hostCalendar = null;
    private $timezone;


    public function __construct(CalendarInterface $applicantCalendar, CalendarInterface $hostCalendar = null, $timezone = 'Europe/London')
    {
        $this->applicantCalendar = $applicantCalendar;
        $this->hostCalendar = $hostCalendar;
        $this->timezone = $timezone;
    }


    /**
     * Get all occupied slots for applicant && host merged
     * @param  User   $applicant
     * @param  User   $host
     * @return array
     */
    private function getAllEvents(User $host, User $applicant = null)
    {
        $applicantEvents = $this->getApplicantEvents($applicant);
        $hostEvents = $this->getHostEvents($host);

        return array_merge($applicantEvents, $hostEvents);
    }


    /**
     * Get all applicant events
     * @param $applicant
     * @return array
     */
    private function getApplicantEvents(User $applicant = null)
    {
        $dbEvents = (!is_null($applicant)) ? $this->getDbEvents($applicant) : [];
        $serviceEvents = $this->toDatesFromService($this->applicantCalendar->listEvents());

        $events = array_merge($dbEvents, $serviceEvents);
        return $events;
    }

    /**
     * Get all host events
     * @param $host
     * @return array
     */
    private function getHostEvents(User $host)
    {
        $dbEvents = $this->getDbEvents($host);
        $serviceEvents = [];
        if (!is_null($this->hostCalendar)) {
            $serviceEvents = $this->toDatesFromService($this->hostCalendar->listEvents());
        }

        $events = array_merge($dbEvents, $serviceEvents);
        return $events;
    }


    /**
     * Get all occupied events from DB - meetings for user
     * @param  User   $user
     * @return array
     */
    private function getDbEvents(User $user)
    {
        $user_meeetings = (new MeetingRepository)->getUserMeetings($user, [], []);
        $slots = [];

        foreach ($user_meeetings as $meeting) {
            // Need to add event time before start of event because meeting is 30min long
            $startDatetime = Carbon::parse($meeting->starting_at)->timezone($this->timezone)->subMinutes(15);
            $endDatetime = Carbon::parse($meeting->starting_at)->timezone($this->timezone)->addMinutes(30);
            $currentDatetime = $startDatetime->copy();

            // Add occupied slots (every 15min) for every service event
            $minutes = 0;
            while ($endDatetime->copy()->subMinutes(15)->toDateTimeString() > $currentDatetime->toDateTimeString()) {
                $currentDatetime = $startDatetime->copy()->addMinutes($minutes);
                $slots[] = $currentDatetime->toW3cString();

                $minutes += 15;
            }
        }

        return $slots;
    }

    /**
     * Get event slots from service events - by 15 minutes
     *
     * @param $events
     * @param string $timezone
     * @return array
     */
    private function toDatesFromService($events)
    {
        $slots = [];

        foreach ($events as $event) {
            // Need to add event time before start of event because meeting is 30min long
            $startDatetime = Carbon::parse(roundToQuarterHour($event->start->dateTime))->subMinutes(15)->timezone($this->timezone);
            $endDatetime = Carbon::parse(roundToQuarterHour($event->end->dateTime, 'up'))->timezone($this->timezone);
            $currentDatetime = $startDatetime->copy();

            // Add occupied slots (every 15min) for every service event
            $minutes = 0;
            while ($endDatetime->copy()->subMinutes(15)->toDateTimeString() > $currentDatetime->toDateTimeString()) {
                $currentDatetime = $startDatetime->copy()->addMinutes($minutes);
                $slots[] = $currentDatetime->toW3cString();

                $minutes += 15;
            }
        }

        return $slots;
    }

    /**
     * @param string $timezone
     * @return array
     */
    private function getHours(User $host)
    {
        $slots = [];

        // Difference between applicant and host
        $hostOffset = $host->utc_offset;
        $applicantOffset = Carbon::now($this->timezone)->offsetHours;
        $timezoneDiff = intval($hostOffset) - intval($applicantOffset);

        // Days
        $startDay = Carbon::today($this->timezone);
        $startDayHost = Carbon::today(intval($hostOffset));
        $endDay = $startDay->copy()->addDays(20);
        $currentDay = $startDay->copy();
        $days = 0;

        // Go through days
        while ($endDay->toDateString() > $currentDay->toDateString()) {
            $currentDay = $startDay->copy()->addDays($days);
            $currentDayHost = $startDayHost->copy()->addDays($days);

            // Applicant start hour - 10am || now() if day is today
            if ($days > 0 || (Carbon::now()->timezone($this->timezone)->toDateTimeString() < $currentDay->copy()->addHours(10)->toDateTimeString())) {
                $applicantStartTime = $currentDay->copy()->addHours(10);
                $applicantEndTime = $applicantStartTime->copy()->addHours(10);
            } else {
                $dayStart = $currentDay->copy()->addHours(10);
                $applicantStartTime = Carbon::parse(roundToQuarterHour(Carbon::now(), 'up'))->timezone($this->timezone);
                $applicantEndTime = $dayStart->copy()->addHours(10);
            }

            $applicantCurrentTime = $applicantStartTime->copy();

            // Host times
            // Host start time depends on timezone difference between him and applicant
            // Example: Host offset = 0, Applicant offset = 1 -> host start of the day is 9am (applicant can't use 10am slot because that is 9am for the host)
            $hostStartDayTime = $currentDayHost->copy()->addHours(10);
            $hostEndDayTime = $hostStartDayTime->copy()->addHours(10);
            $hostStartTime = $applicantStartTime->copy()->addHours($timezoneDiff);
            $hostCurrentTime = $hostStartTime->copy();

            $minutes = 0;
            // Get hour slots - every 15 minutes
            while ($applicantEndTime->toDateTimeString() > $applicantCurrentTime->toDateTimeString()) {
                $applicantCurrentTime = $applicantStartTime->copy()->addMinutes($minutes);
                $hostCurrentTime = $hostStartTime->copy()->addMinutes($minutes);

                // We can use only host current time if it's between applicant start and end times (10am and 8pm)
                if ($hostCurrentTime->toDateTimeString() >= $hostStartDayTime->toDateTimeString() && $hostCurrentTime->toDateTimeString() <= $hostEndDayTime->toDateTimeString()) {
                    $slots[] = $applicantCurrentTime->toW3cString();
                }

                $minutes += 15;
            }

            $days++;
        }

        return $slots;
    }


    /**
     * All slots - merge all hours and events hours
     * @param  User   $host      [description]
     * @param  User $applicant || null
     * @return array
     */
    public function getAvailableSlots(User $host, User $applicant = null)
    {
        $eventSlots = $this->getAllEvents($host, $applicant);
        $daySlots = $this->getHours($host, 1);

        // Get only available slots
        $availableSlots = array_diff($daySlots, $eventSlots);

        // Format datetimes in array - month/week/day
        $data = [];
        foreach ($availableSlots as $slot) {
            $carbon = Carbon::parse($slot);
            $month = $carbon->month;
            $week = $carbon->weekOfYear;
            $day = $carbon->toDateString();

            $data[$month][$week][$day][] = $slot;
        }

        return $data;
    }

    /**
     * Get suggested slots - 3
     * @param  array  $slots
     * @return array
     */
    public function getSuggestedSlots(array $slots)
    {
        $suggested_slots = [];

        foreach ($slots as $month) {
            foreach ($month as $week) {
                foreach ($week as $day) {
                    // Break if we have 3 suggested dates
                    if (count($suggested_slots) >= 3) {
                        break 2;
                    }

                    // Add first datetime from day if there is any in that day
                    if (count($day)) {
                        $time = $day[0];
                        $suggested_slots[] = $time;
                    }
                }
            }
        }

        return $suggested_slots;
    }
}
