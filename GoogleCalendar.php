<?php

namespace ThirtyMin\Services\Calendar;

use Google_Client;
use Google_Service_Calendar;
use Log;
use ThirtyMin\Exceptions\API\GoogleCalendarException;
use ThirtyMin\Services\GoogleClientService;
use ThirtyMin\Models\User;

/**
 * Class GoogleCalendar
 * @package App\Services\Calendar
 */
class GoogleCalendar extends GoogleClientService implements CalendarInterface
{
    // protected $client;
    protected $service;
    protected $calendarId = 'primary';

    /**
     * Provide access token if you intend to access Google API for user data
     *
     * @param null $access_token
     */
    public function __construct($access_token = null, $refresh_token = null)
    {        
        try {
            $client = new Google_Client();
            $client->setAuthConfig(config('api-credentials.google.calendar_json_file'));
            $client->addScope(Google_Service_Calendar::CALENDAR);
            $client->setAccessType('offline');
        
            $guzzleClient = new \GuzzleHttp\Client(array('curl' => array(CURLOPT_SSL_VERIFYPEER => false)));
            $client->setHttpClient($guzzleClient);
        
            if (!is_null($access_token)) {
                $client->setAccessToken($access_token);
            } else {
                $access_token = $client->fetchAccessTokenWithRefreshToken($refresh_token);
                $client->setAccessToken($access_token);
            }
        
            $this->client = $client;            
            $this->service = new Google_Service_Calendar($this->client);
        
            // Test if user is logged in to Google
            $this->listEvents();
        
        } catch (\Exception $e) {
            throw new GoogleCalendarException($e->getMessage(), $e->getCode());
        }
    }

    /**
     * @param bool|false $dates
     * @param array $optParams
     * @return array|mixed
     */
    public function listEvents($dates = false, $optParams = [])
    {
        if (empty($optParams)) {
            $optParams = $this->getOptinalParams();
        }

        try {
            $results = $this->service->events->listEvents($this->calendarId, $optParams);
        } catch (\Exception $e) {
            throw new GoogleCalendarException($e->getMessage(), $e->getCode());
        }

        return $dates ? $this->toDatesArray($results->getItems()) : $results->getItems();
    }
    
    /**
     * Get event start time
     * @param  $event
     * @return Datetime
     */
    public function getStartDatetime($event) 
    {
        return $event->start->dateTime;
    }
    
    /**
     * Get event end time
     * @param  $event
     * @return Datetime
     */
    public function getEndDatetime($event) 
    {
        return $event->end->dateTime;
    }

    /**
     * @return array
     */
    private function getOptinalParams()
    {
        return [
            'maxResults' => 100,
            'orderBy' => 'startTime',
            'singleEvents' => true,
            'timeMin' => date('c'),
            'alwaysIncludeEmail' => true,
            'timeZone' => true
        ];
    }

    /**
     * @param array $events
     * @return array
     */
    public function toDatesArray(array $events)
    {
        $dates = [];

        foreach ($events as $event) {
            $obj = [$event->start->dateTime, $event->end->dateTime];
            $dates[] = $obj;
        }

        return $dates;
    }         
    
    /**
     * Get user info data
     * @return mixed
     */
    public function getUserData() {
        $oauth2 = new \Google_Service_Oauth2($this->client);
        return $oauth2->userinfo->get();
    }   
    
}
