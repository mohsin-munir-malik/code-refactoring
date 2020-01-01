<?php


namespace App\Services;


use DTApi\Helpers\TeHelper;

class JobsPushNotificationsService
{
    public function __construct()
    {
        $this->logger = new Logger('admin_logger');
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    private function makeCurlCall($job_id, array $fields)
    {
        $env = env('APP_ENV') == 'prod' ? 'prod' : 'dev';
        $onesignalAppID = config("app.{$env}OnesignalAppID");
        $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config("app.{$env}OnesignalApiKey"));
        $fields['app_id'] = $onesignalAppID;

        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $onesignalRestAuthKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        curl_close($ch);
        $this->logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        $this->logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);

        $user_tags = $this->getUserTagsStringFromArray($users);
        $data['job_id'] = $job_id;

        $android_sound = 'default';
        if ($data['notification_type'] == 'suitable_job') {
            $android_sound = $data['immediate'] == 'no' ? 'normal_booking' : 'emergency_booking';
        }
        $ios_sound = "$android_sound.mp3";

        $fields = array(
            'tags'           => json_decode($user_tags),
            'data'           => $data,
            'title'          => array('en' => 'DigitalTolk'),
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound
        );
        if ($is_need_delay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $next_business_time;
        }

        $this->makeCurlCall($job_id, $fields);
    }

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $user_tags = "[";
        $first = true;
        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $user_tags .= ',{"operator": "OR"},';
            }
            $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }
        $user_tags .= ']';
        return $user_tags;
    }

    /*
 * TODO remove method and add service for notification
 * TEMP method
 * send session start remind notification
 */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {
        if (!$this->isNeedToSendPush($user->id)) {
            return;
        }
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        else
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );

        $users_array = array($user);
        $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
    }

    /**
     * Function to check if need to send the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
        return TeHelper::getUsermeta($user_id, 'not_get_notification') != 'yes';
    }

    /**
     * Function to delay the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToDelayPush($user_id)
    {
        if (!DateTimeHelper::isNightTime()) return false;
        return ! $this->isNeedToSendPush($user_id);
    }

    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $users = User::all();
        $translator_array = array();            // suitable translators (no need to delay push)
        $delpay_translator_array = array();     // suitable translators (need to delay push)

        foreach ($users as $oneUser) {
            if (! ($oneUser->user_type == '2' && $oneUser->status == '1' && $oneUser->id != $exclude_user_id)) {
                continue;
            }
            if (!$this->isNeedToSendPush($oneUser->id)) continue;
            $not_get_emergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');
            if ($data['immediate'] == 'yes' && $not_get_emergency == 'yes') continue;

            $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id); // get all potential jobs of this user
            foreach ($jobs as $oneJob) {
                if ($job->id != $oneJob->id) { // one potential job is the same with current job
                    continue;
                }

                $userId = $oneUser->id;
                $job_for_translator = Job::assignedToPaticularTranslator($userId, $oneJob->id);
                if ($job_for_translator != 'SpecificJob') {
                    continue;
                }

                $job_checker = Job::checkParticularJob($userId, $oneJob);
                if ($job_checker == 'userCanNotAcceptJob') {
                    continue;
                }

                if ($this->isNeedToDelayPush($oneUser->id)) {
                    $delpay_translator_array[] = $oneUser;
                } else {
                    $translator_array[] = $oneUser;
                }
            }

        }
        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        if ($data['immediate'] == 'no') {
            $msg_contents = 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'];
        } else {
            $msg_contents = 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';
        }
        $msg_text = array(
            "en" => $msg_contents
        );

        $this->logger->addInfo('Push send for job ' . $job->id, [$translator_array, $delpay_translator_array, $msg_text, $data]);
        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false);       // send new booking push to suitable translators(not delay)
        $this->sendPushNotificationToSpecificUsers($delpay_translator_array, $job->id, $data, $msg_text, true); // send new booking push to suitable translators(need to delay)
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    private function sendExpiredNotification($job, $user)
    {
        if (! $this->isNeedToSendPush($user->id)) {
            return;
        }
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        ];
        $this->sendPushNotificationToSpecificUsers([$user], $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
    }

    /**
     * send session start remind notificatio
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        if (! $this->isNeedToSendPush($user->id)) {
            return;
        }
        $data['notification_type'] = 'session_start_remind';
        $location = $job->customer_physical_type == 'yes' ? 'platstolkningen' : 'telefontolkningen';
        $msg_text = [
            "en" => $location . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
        ];

        $this->sendPushNotificationToSpecificUsers([$user], $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
    }

    public function sendJobCanceledNotificationToCustomer($job, $translator)
    {
        if (! $this->isNeedToSendPush($translator->id)) {
            return;
        }
        $data['notification_type'] = 'job_cancelled';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = array(
            "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
        );
        $users_array = array($translator);
        $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));// send Session Cancel Push to Translaotor
    }

    public function sendJobCanceledNotificationToNonCustomer($job)
    {
        if (! $customer = $job->user()->get()->first()) {
            return;
        }

        if (! $this->isNeedToSendPush($customer->id)) {
            return;
        }

        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = array(
            "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
        );
        $users_array = array($customer);
        $data['notification_type'] = 'job_cancelled';
        $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));     // send Session Cancel Push to customer
    }
}