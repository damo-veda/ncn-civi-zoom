<?php
use CRM_Ncnciviapi_ExtensionUtil as E;

use Firebase\JWT\JWT;
use Zttp\Zttp;


/**
 * Participant.GenerateWebinarAttendance specification
 *
 * Makes sure that the verification token is provided as a parameter
 * in the request to make sure that request is from a reliable source.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_event_generatezoomattendance_spec(&$spec) {
	$spec['days'] = [
    'title' => 'Select Events ended in past x Days',
    'description' => 'Events ended how many days before you need to select?',
    'type' => CRM_Utils_Type::T_INT,
    'api.required' => 1,
  ];
}

/**
 * Participant.GenerateWebinarAttendance API
 *
 * Designed to be called by a Zoom Event Subscription (event: webinar.ended).
 * Once invoked, it gets the absent registrants from the webinar that just ended.
 *
 * Then, it gets the event associated with the webinar, as well as, the
 * registered participants of the event.
 *
 * Absent registrants are then subtracted from registered participants and,
 * the remaining participants' statuses are set to Attended.
 *
 * @param array $params
 *
 * @return array
 *   Array containing data of found or newly created contact.
 *
 * @see civicrm_api3_create_success
 *
 */
function civicrm_api3_event_generatezoomattendance($params) {
	$allAttendees = [];
	$days = $params['days'];
	$pastDateTimeFull = new DateTime();
	$pastDateTimeFull = $pastDateTimeFull->modify("-".$days." days");
	$pastDate = $pastDateTimeFull->format('Y-m-d');
	$currentDate = date('Y-m-d');

  $apiResult = civicrm_api3('Event', 'get', [
    'sequential' => 1,
    'end_date' => ['BETWEEN' => [$pastDate, $currentDate]],
  ]);
	$allEvents = $apiResult['values'];
	$eventIds = [];
	foreach ($allEvents as $key => $value) {
		$eventIds[] = $value['id'];
	}
	foreach ($eventIds as $eventId) {
		$settings = CRM_NcnCiviZoom_Utils::getZoomSettingsByEventId($eventId);
		$key = $settings['secret_key'];
		$payload = array(
		    "iss" => $settings['api_key'],
		    "exp" => strtotime('+1 hour')
		);
		$jwt = JWT::encode($payload, $key);
		$webinarId = getWebinarID($eventId);
		$meetingId = getMeetingID($eventId);
		if(!empty($webinarId)){
			$entityId = $webinarId;
			$url = $settings['base_url'] . "/past_webinars/$webinar/absentees?page=$page";
			$entity = "Webinar";
		}elseif(!empty($meetingId)){
			$entityId = $meetingId;
			$url = $settings['base_url'] . "/past_meetings/$meetingId/participants";
			$entity = "Meeting";
		}else{
			continue;
		}

		$token = $jwt;

		$page = 0;

		// Get absentees from Zoom API
		$response = Zttp::withHeaders([
			'Content-Type' => 'application/json;charset=UTF-8',
			'Authorization' => "Bearer $token"
		])->get($url);

		$attendees = [];
		if($entity == "Webinar"){
			$pages = $response->json()['page_count'];

			// Store registrants who did not attend the webinar
			$absentees = $response->json()['registrants'];

			$absenteesEmails = [];

			while($page < $pages) {
				foreach($absentees as $absentee) {
					$email = $absentee['email'];

					array_push($absenteesEmails, "'$email'");
				}

				$attendees = array_merge($attendees, selectAttendees($absenteesEmails, $eventId));

				$page++;

				// Get and loop through all of webinar registrants
				$url = $settings['base_url'] . "/past_webinars/$webinar/absentees?page=$page";

				// Get absentees from Zoom API
				$response = Zttp::withHeaders([
					'Content-Type' => 'application/json;charset=UTF-8',
					'Authorization' => "Bearer $token"
				])->get($url);

				// Store registrants who did not attend the webinar
				$absentees = $response->json()['registrants'];

				$absenteesEmails = [];
			}
		}elseif ($entity == "Meeting") {
			$attendeesEmails = [];
			$participants = $response->json()['participants'];
			foreach ($participants as $key => $value) {
				$attendeesEmails[] = $value['user_email'];
			}
			$attendees = selectAttendees($attendeesEmails, $eventId, "Meeting");
		}
		updateAttendeesStatus($attendees, $eventId);
		$allAttendees[] = $attendees;
	}
	$return['allAttendees'] = $allAttendees;

	return civicrm_api3_create_success($return, $params, 'Event');
}

/**
 * Queries for the registered participants that weren't absent
 * during the webinar.
 * @param  array $absenteesEmails emails of registrants absent from the webinar
 * @param  int $event the id of the webinar's associated event
 * @return array participants (email, participant_id, contact_id) who weren't absent
 */
function selectAttendees($emails, $event, $entity = "Webinar") {
	if($entity == "Webinar"){
		$absenteesEmails = join("','",$emails);

		$selectAttendees = "
			SELECT
				e.email,
				p.contact_id,
				p.id AS participant_id
			FROM civicrm_participant p
			LEFT JOIN civicrm_email e ON p.contact_id = e.contact_id
			WHERE
				e.email NOT IN ('$absenteesEmails') AND
		    	p.event_id = {$event}";
	}elseif($entity == "Meeting"){
		$attendeesEmails = join("','",$emails);

		$selectAttendees = "
			SELECT
				e.email,
				p.contact_id,
				p.id AS participant_id
			FROM civicrm_participant p
			LEFT JOIN civicrm_email e ON p.contact_id = e.contact_id
			WHERE
				e.email IN ('$attendeesEmails') AND
		    	p.event_id = {$event}";
	}
	// Run query
	$query = CRM_Core_DAO::executeQuery($selectAttendees);

	$attendees = [];

	while($query->fetch()) {
		array_push($attendees, [
			'email' => $query->email,
			'contact_id' => $query->contact_id,
			'participant_id' => $query->participant_id
		]);
	}

	return $attendees;
}

/**
 * Set the status of the registrants who weren't absent to Attended.
 * @param  array $attendees registrants who weren't absent
 * @param  int $event the event associated with the webinar
 *
 */
function updateAttendeesStatus($attendees, $event) {
	foreach($attendees as $attendee) {
		$rr = civicrm_api3('Participant', 'create', [
		  'event_id' => $event,
		  'id' => $attendee['participant_id'],
		  'status_id' => "Attended",
		]);
	}
}


/**
 * Get an event's webinar id
 * @param  int $event The event's id
 * @return string The event's webinar id
 */
function getWebinarID($eventId) {
	$result;
	$customField = CRM_NcnCiviZoom_Utils::getWebinarCustomField();
	try {
		$apiResult = civicrm_api3('Event', 'get', [
		  'sequential' => 1,
		  'return' => [$customField],
		  'id' => $eventId,
		]);
		$result = null;
		if(!empty($apiResult['values'][0][$customField])){
			// Remove any empty spaces
			$result = trim($apiResult['values'][0][$customField]);
			$result = str_replace(' ', '', $result);
		}
	} catch (Exception $e) {
		throw $e;
	}

	return $result;
}

/**
 * Get an event's Meeting id
 * @param  int $event The event's id
 * @return string The event's Meeting id
 */
function getMeetingID($eventId) {
	$result;
	$customField = CRM_NcnCiviZoom_Utils::getMeetingCustomField();
	try {
		$apiResult = civicrm_api3('Event', 'get', [
		  'sequential' => 1,
		  'return' => [$customField],
		  'id' => $eventId,
		]);
		$result = null;
		if(!empty($apiResult['values'][0][$customField])){
			// Remove any empty spaces
			$result = trim($apiResult['values'][0][$customField]);
			$result = str_replace(' ', '', $result);
		}
	} catch (Exception $e) {
		throw $e;
	}

	return $result;
}