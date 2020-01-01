<?php

namespace DTApi\Repository;

use App\Services\JobsMailTrait;
use App\Services\JobsPaginator;
use App\Services\JobsPushNotificationsService;
use App\Services\JobsPushNotificationsTrait;
use App\Services\JobsService;
use App\Services\JobsSMSTrait;
use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class JobRepository extends BaseRepository
{
    use JobsPaginator;

    protected $model;
    protected $mailer;
    protected $logger;
    /**
     * @var JobsPushNotificationsService
     */
    private $pushNotificationsService;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer, JobsPushNotificationsService $pushNotificationsService)
    {
        parent::__construct($model);
        $this->mailer = $mailer;

        $this->logger = new Logger('admin_logger');
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $this->pushNotificationsService = $pushNotificationsService;
    }

    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {
        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        if (Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
            return $response;
        }

        if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
            $job->status = 'assigned';
            $job->save();
            $this->sendJobAcceptedEmail($job);
        }
        /*@todo
            add flash message here.
        */
        $jobs = $this->getPotentialJobs($cuser);
        $response = array();
        $response['list'] = json_encode(['jobs' => $jobs, 'job' => $job], true);
        $response['status'] = 'success';

        return $response;
    }

    /*Function to accept the job with the job id*/
    public function acceptJobWithId($job_id, $cuser)
    {
        $job = Job::findOrFail($job_id);
        $response = array();

        if (Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            // You already have a booking the time
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
            return $response;
        }

        if ($job->status != 'pending' || !Job::insertTranslatorJobRel($cuser->id, $job_id)) {
            // Booking already accepted by someone else
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            $response['status'] = 'fail';
            $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            return $response;
        }

        $job->status = 'assigned';
        $job->save();
        $this->sendJobAcceptedEmail($job);
        $user = $job->user()->get()->first();
        $data = array();
        $data['notification_type'] = 'job_accepted';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = array(
            "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
        );
        if ($this->pushNotificationsService->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->pushNotificationsService->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->pushNotificationsService->isNeedToDelayPush($user->id));
        }
        // Your Booking is accepted sucessfully
        $response['status'] = 'success';
        $response['list']['job'] = $job;
        $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;

        return $response;
    }

    public function cancelJobAjax($data, $user)
    {
        $response = array();
        /*@todo
            add 24hrs loging here.
            If the cancelation is before 24 hours before the booking tie - supplier will be informed. Flow ended
            if the cancelation is within 24
            if cancelation is within 24 hours - translator will be informed AND the customer will get an addition to his number of bookings - so we will charge of it if the cancelation is within 24 hours
            so we must treat it as if it was an executed session
        */
        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        if ($cuser->is('customer')) {
            $job->withdraw_at = Carbon::now();
            if ($job->withdraw_at->diffInHours($job->due) >= 24) {
                $job->status = 'withdrawbefore24';
                $response['jobstatus'] = 'success';
            } else {
                $job->status = 'withdrawafter24';
                $response['jobstatus'] = 'success';
            }
            $job->save();
            Event::fire(new JobWasCanceled($job));
            $response['status'] = 'success';
            $response['jobstatus'] = 'success';
            if ($translator) {
                $this->pushNotificationsService->sendJobCanceledNotificationToCustomer($job, $translator);
            }

            return $response;
        }

        if ($job->due->diffInHours(Carbon::now()) <= 24) {
            $response['status'] = 'fail';
            $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
            return $response;
        }

        $this->pushNotificationsService->sendJobCanceledNotificationToNonCustomer($job);
        $job->status = 'pending';
        $job->created_at = date('Y-m-d H:i:s');
        $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
        $job->save();
        Job::deleteTranslatorJobRel($translator->id, $job_id);

        $data = $this->jobToData($job);

        $this->pushNotificationsService->sendNotificationTranslator($job, $data, $translator->id);   // send Push all sutiable translators
        $response['status'] = 'success';
        return $response;
    }

    public function endJob($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);

        if($job_detail->status != 'started')
            return ['status' => 'success'];

        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $this->sendJobEndedEmail($job);
        $this->sendJobSessionEndedEmail($job);

        $job->save();

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['user_id'];
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }
}