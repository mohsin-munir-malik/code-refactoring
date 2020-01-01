<?php

namespace DTApi\Repository;

use App\Services\JobsMailService;
use App\Services\JobsPaginator;
use App\Services\JobsPushNotificationsService;
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
class BookingRepository extends BaseRepository
{
    use JobsSMSTrait, JobsPaginator;

    protected $model;
    protected $mailer;
    protected $logger;
    /**
     * @var JobsMailService
     */
    private $mailService;
    /**
     * @var JobsPushNotificationsService
     */
    private $pushNotificationsService;

    /**
     * @param Job $model
     */
    function __construct(Job $model, JobsMailService $mailService, JobsPushNotificationsService $pushNotificationsService)
    {
        parent::__construct($model);
        $this->logger = new Logger('admin_logger');
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $this->mailService = $mailService;
        $this->pushNotificationsService = $pushNotificationsService;
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        $cuser = User::find($user_id);
        $usertype = '';

        if (!$cuser) {
            return [
                'emergencyJobs' => [],
                'noramlJobs' => [],
                'cuser' => $cuser,
                'usertype' => ''
            ];
        }

        $jobsService = new JobsService();
        $jobs = [];
        if ($cuser->is('customer')) {
            $jobs = $jobsService->getCustomerJobs($cuser);
            $usertype = 'customer';
        } elseif ($cuser->is('translator')) {
            $jobs = $jobsService->getTranslatorJobs($cuser);
            $usertype = 'translator';
        }
        list($emergencyJobs, $noramlJobs) = $jobs->partition(function ($job) {
            return $job->immediate == 'yes';
        });
        $noramlJobs->transform(function ($job) use ($user_id) {
            $job['usercheck'] = Job::checkParticularJob($user_id, $job);
        })
            ->sortBy('due');

        return [
            'emergencyJobs' => $emergencyJobs,
            'noramlJobs' => $noramlJobs,
            'cuser' => $cuser,
            'usertype' => $usertype
        ];
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $pagenum = $request->get('page', 1);
        $cuser = User::find($user_id);

        if (!$cuser) {
            return ;
        }

        if ($cuser->is('customer')) {
            $jobs = $cuser->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                ->orderBy('due', 'desc')
                ->paginate(15);
            return [
                'emergencyJobs' => [],
                'noramlJobs' => [],
                'jobs' => $jobs,
                'cuser' => $cuser,
                'usertype' => 'customer',
                'numpages' => 0,
                'pagenum' => 0
            ];
        }

        if ($cuser->is('translator')) {
            $jobs = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pagenum);
            $numpages = ceil($jobs->total() / 15);

            return [
                'emergencyJobs' => [],
                'noramlJobs' => $jobs,
                'jobs' => $jobs,
                'cuser' => $cuser,
                'usertype' => 'translator',
                'numpages' => $numpages,
                'pagenum' => $pagenum
            ];
        }
    }

    private function validateStore(User $user, array $data)
    {
        if ($user->user_type != env('CUSTOMER_ROLE_ID')) {
            $response['status'] = 'fail';
            $response['message'] = "Translator can not create booking";
            return $response;
        }

        if (!isset($data['from_language_id'])) {
            $response['status'] = 'fail';
            $response['message'] = "Du måste fylla in alla fält";
            $response['field_name'] = "from_language_id";
            return $response;
        }

        if ($data['immediate'] != 'no') {
            if (array_get($data, 'duration') == '') {
                $response['status'] = 'fail';
                $response['message'] = "Du måste fylla in alla fält";
                $response['field_name'] = "duration";
                return $response;
            }
        }

        if (array_get($data, 'due_date') == '') {
            $response['status'] = 'fail';
            $response['message'] = "Du måste fylla in alla fält";
            $response['field_name'] = "due_date";
            return $response;
        }
        if (array_get($data, 'due_time') == '') {
            $response['status'] = 'fail';
            $response['message'] = "Du måste fylla in alla fält";
            $response['field_name'] = "due_time";
            return $response;
        }
        if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
            $response['status'] = 'fail';
            $response['message'] = "Du måste göra ett val här";
            $response['field_name'] = "customer_phone_type";
            return $response;
        }
        if (array_get($data, 'duration') == '') {
            $response['status'] = 'fail';
            $response['message'] = "Du måste fylla in alla fält";
            $response['field_name'] = "duration";
            return $response;
        }

        if ($data['immediate'] != 'yes') {
            $due = $data['due_date'] . " " . $data['due_time'];
            $response['type'] = 'regular';
            $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
            $data['due'] = $due_carbon->format('Y-m-d H:i:s');
            if ($due_carbon->isPast()) {
                $response['status'] = 'fail';
                $response['message'] = "Can't create booking in past";
                return $response;
            }
        }

        return true;
    }

    private function transformJobInputData($data, $consumerType)
    {
        $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';
        $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';

        if ($data['immediate'] == 'yes') {
            $data['due'] = Carbon::now()->addMinute($immediatetime = 5)->format('Y-m-d H:i:s');
            $data['immediate'] = 'yes';
            $data['customer_phone_type'] = 'yes';
        } else {
            $due = $data['due_date'] . " " . $data['due_time'];
            $response['type'] = 'regular';
            $data['due'] = Carbon::createFromFormat('m/d/Y H:i', $due)->format('Y-m-d H:i:s');
        }
        if (in_array('male', $data['job_for'])) {
            $data['gender'] = 'male';
        } else if (in_array('female', $data['job_for'])) {
            $data['gender'] = 'female';
        }

        if (in_array('normal', $data['job_for'])) {
            $data['certified'] = 'normal';
        } else if (in_array('certified', $data['job_for'])) {
            $data['certified'] = 'yes';
        } else if (in_array('certified_in_law', $data['job_for'])) {
            $data['certified'] = 'law';
        } else if (in_array('certified_in_helth', $data['job_for'])) {
            $data['certified'] = 'health';
        }
        if (in_array('normal', $data['job_for']) && in_array('certified', $data['job_for'])) {
            $data['certified'] = 'both';
        } else if(in_array('normal', $data['job_for']) && in_array('certified_in_law', $data['job_for'])) {
            $data['certified'] = 'n_law';
        } else if(in_array('normal', $data['job_for']) && in_array('certified_in_helth', $data['job_for'])) {
            $data['certified'] = 'n_health';
        }

        $jobTypesMap = [
            'rwsconsumer' => 'rws',
            'ngo' => 'unpaid',
            'paid' => 'paid',
        ];
        $data['job_type'] = @$jobTypesMap[$consumerType];

        $data['b_created_at'] = date('Y-m-d H:i:s');
        if (isset($due))
            $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);

        $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';

        return $data;
    }

    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store(User $cuser, array $data)
    {
        // validate input data and return failed response is data is not correct
        if ($response = $this->validateStore($cuser, $data) !== true) {
            return $response;
        }

        $data = $this->transformJobInputData($data, $cuser->userMeta->consumer_type);

        $job = $cuser->jobs()->create($data);

        $response['customer_physical_type'] = $data['customer_physical_type'];
        $response['status'] = 'success';
        $response['id'] = $job->id;
        return $response;
    }

    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $user_type = $data['user_type'];
        $job = Job::findOrFail(@$data['user_email_job_id']);
        $job->user_email = @$data['user_email'];
        $job->reference = isset($data['reference']) ? $data['reference'] : '';
        $user = $job->user()->get()->first();
        if (isset($data['address'])) {
            $job->address = ($data['address'] != '') ? $data['address'] : $user->userMeta->address;
            $job->instructions = ($data['instructions'] != '') ? $data['instructions'] : $user->userMeta->instructions;
            $job->town = ($data['town'] != '') ? $data['town'] : $user->userMeta->city;
        }
        $job->save();

        $this->mailService->sendJobCreatedEmail($job);

        $response['type'] = $user_type;
        $response['job'] = $job;
        $response['status'] = 'success';
        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));
        return $response;

    }

    /**
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {
        $data = array_only($job->toArray(), [
            'from_language_id',
            'immediate',
            'duration',
            'status',
            'gender',
            'certified',
            'due',
            'job_type',
            'customer_phone_type',
            'customer_physical_type',
            'customer_town',
        ]);
        $data['job_id'] = $job->id;
        $data['customer_type'] = $job->user->userMeta->customer_type;

        list($due_date, $due_time) = explode(" ", $job->due);

        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;

        $data['job_for'] = array();
        if ($job->gender == 'male') {
            $data['job_for'][] = 'Man';
        } else if ($job->gender == 'female') {
            $data['job_for'][] = 'Kvinna';
        }

        if ($job->certified == 'both') {
            $data['job_for'][] = 'Godkänd tolk';
            $data['job_for'][] = 'Auktoriserad';
        } else if ($job->certified == 'yes') {
            $data['job_for'][] = 'Auktoriserad';
        } else if ($job->certified == 'n_health') {
            $data['job_for'][] = 'Sjukvårdstolk';
        } else if ($job->certified == 'law' || $job->certified == 'n_law') {
            $data['job_for'][] = 'Rätttstolk';
        } else if($job->certified != null) {
            $data['job_for'][] = $job->certified;
        }

        return $data;
    }

    /**
     * @param array $post_data
     */
    public function jobEnd($post_data = array())
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job = Job::with('translatorJobRel')->findOrFail($jobid);
        $diff = date_diff(date_create($completeddate), date_create($job->due));
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $diff->h . ':' . $diff->i . ':' . $diff->s;

        $this->mailService->sendJobCompletedEmail($job);

        $job->save();

        $tr = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($post_data['userid'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $this->mailService->sendSessionEndEmail($tr);

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['userid'];
        $tr->save();
    }

    private function translatorToJobMap()
    {
        return [
            'professional' => 'pain', /*show all jobs for professionals.*/
            'rwstranslator' => 'rws', /* for rwstranslator only show rws jobs. */
            'volunteer' => 'unpaid', /* for volunteers only show unpaid jobs. */
        ];
    }


    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta = UserMeta::where('user_id', $user_id)->firstOrFail();
        $job_type = array_get($this->translatorToJobMap(), $user_meta->translator_type, 'unpaid');

        $languages = UserLanguages::where('user_id', '=', $user_id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;
        $job_ids = Job::getJobs($user_id, $job_type, 'pending', $userlanguage, $gender, $translator_level);

        // filter jobs
        $job_ids = Job::whereIn('id', collect($job_ids)->pluck('id'))
            ->get()
            ->filter(function ($job) use ($user_id) {
                return ($job->customer_phone_type == 'no' || $job->customer_phone_type == '')
                    && $job->customer_physical_type == 'yes'
                    && Job::checkTowns($job->user_id, $user_id) == false;
            });

        return TeHelper::convertJobIdsInObjs($job_ids);
    }

    private function getTranslatorLevelsFromJob($job)
    {
        if (empty($job->certified)) {
            return [];
        }

        $levels = [
            'c' => 'Certified',
            'c_lwa' => 'Certified with specialisation in law',
            'c_hc' => 'Certified with specialisation in health care',
            'layman' => 'Layman',
            'read_courses' => 'Read Translation courses',
        ];

        if ($job->certified == 'yes' || $job->certified == 'both') {
            return array_only($levels, ['c', 'c_lwa', 'c_hc']);
        }
        if($job->certified == 'law' || $job->certified == 'n_law') {
            return array_only($levels, ['c_lwa']);
        }
        if($job->certified == 'health' || $job->certified == 'n_health') {
            return array_only($levels, ['c_hc']);
        }
        if ($job->certified == 'normal' || $job->certified == 'both') {
            return array_only($levels, ['layman', 'read_courses']);
        }

        if ($job->certified == null) {
            return $levels;
        }

        return [];
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {
        $translator_type = array_get(array_flip($this->translatorToJobMap()), $job->job_type);
        $translator_level = $this->getTranslatorLevelsFromJob($job);

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translatorsId = collect($blacklist)->pluck('translator_id')->all();
        return User::getPotentialUsers($translator_type, $job->from_language_id, $job->gender, $translator_level, $translatorsId);
    }

    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
    {
        $job = Job::find($id);

        $current_translator = $job->translatorJobRel->where('cancel_at', Null)->first();
        if (is_null($current_translator))
            $current_translator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();

        $log_data = [];

        $langChanged = false;

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) $log_data[] = $changeTranslator['log_data'];

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged'])
            $log_data[] = $changeStatus['log_data'];

        $job->admin_comments = $data['admin_comments'];

        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);

        $job->reference = $data['reference'];
        $job->save();

        if ($job->due <= Carbon::now()) {
            return ['Updated'];
        }

        if ($changeDue['dateChanged']) {
            $this->mailService->sendChangedDateNotification($job, $old_time);
        }
        if ($changeTranslator['translatorChanged']) {
            $this->mailService->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
        }
        if ($langChanged) {
            $this->mailService->sendChangedLangNotification($job, $old_lang);
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        if ($old_status == $data['status']) {
            return;
        }

        switch ($job->status) {
            case 'timedout':
                $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                break;
            case 'completed':
                $statusChanged = $this->changeCompletedStatus($job, $data);
                break;
            case 'started':
                $statusChanged = $this->changeStartedStatus($job, $data);
                break;
            case 'pending':
                $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                break;
            case 'withdrawafter24':
                $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                break;
            case 'assigned':
                $statusChanged = $this->changeAssignedStatus($job, $data);
                break;
            default:
                $statusChanged = false;
                break;
        }

        if (! $statusChanged) {
            return;
        }

        $log_data = [
            'old_status' => $old_status,
            'new_status' => $data['status']
        ];
        return ['statusChanged' => true, 'log_data' => $log_data];
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        if ($data['status'] == 'pending') {
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();

            $this->mailService->sendJobStatusChangedToCustEmail($job);
            $this->pushNotificationsService->sendNotificationTranslator($job, $this->jobToData($job), '*');   // send Push all sutiable translators
            return true;
        }

        if ($changedTranslator) {
            $job->save();
            $this->mailService->sendJobAcceptedEmail($job);
            return true;
        }

        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {
        $job->status = $data['status'];
        if ($data['status'] == 'timedout') {
            if ($data['admin_comments'] == '') {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];
        }
        $job->save();
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '') return false;
        if ($data['status'] != 'completed') {
            $job->save();
            return true;
        }
        if ($data['sesion_time'] == '') return false;

        $job->admin_comments = $data['admin_comments'];
        $job->end_at = date('Y-m-d H:i:s');
        $job->session_time = $data['sesion_time'];
        $this->mailService->changeStatusStartedEmail($job);

        $job->save();
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
        $job->admin_comments = $data['admin_comments'];
        $this->mailService->changeStatusPendingEmail($job, $data, $changedTranslator);
        $user = $job->user()->first();
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->pushNotificationsService->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
        $this->pushNotificationsService->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
        $job->save();
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        if (! in_array($data['status'], ['timedout'])) {
            return false;
        }
        if ($data['admin_comments'] == '') return false;

        $job->status = $data['status'];
        $job->admin_comments = $data['admin_comments'];
        $job->save();
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        if( !in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            return false;
        }
        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') {
            return false;
        }

        $job->status = $data['status'];

        $job->admin_comments = $data['admin_comments'];
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
            $this->mailService->changeStatusAssignedEmail($job);
        }
        $job->save();
        return true;
    }

    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;

        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            $log_data = [];
            if (!is_null($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = $current_translator->toArray();
                $new_translator['user_id'] = $data['translator'];
                unset($new_translator['id']);
                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            }
            if ($translatorChanged)
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];

        }

        return ['translatorChanged' => $translatorChanged];
    }

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        if ($old_due == $new_due) {
            return ['dateChanged' => false];
        }

        $log_data = [
            'old_due' => $old_due,
            'new_due' => $new_due
        ];
        return ['dateChanged' => true, 'log_data' => $log_data];
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();
        $data = $this->jobToData($job);
        $data['job_id'] = $job->id;
        $data['customer_town'] = $user_meta->city;
        $data['customer_type'] = $user_meta->customer_type;

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];
        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;
        $data['job_for'] = array();
        if ($job->gender == 'male') {
            $data['job_for'][] = 'Man';
        } else if ($job->gender == 'female') {
            $data['job_for'][] = 'Kvinna';
        }
        if ($job->certified == 'both') {
            $data['job_for'][] = 'normal';
            $data['job_for'][] = 'certified';
        } else if ($job->certified == 'yes') {
            $data['job_for'][] = 'certified';
        } else if($job->certified != null) {
            $data['job_for'][] = $job->certified;
        }
        $this->pushNotificationsService->sendNotificationTranslator($job, $data, '*');   // send Push all sutiable translators
    }

    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $job_type = array_get($this->translatorToJobMap(), $cuser_meta->translator_type, 'unpaid');

        $languages = UserLanguages::where('user_id', '=', $cuser->id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;
        /*Call the town function for checking if the job physical, then translators in one town can get job*/
        $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $userlanguage, $gender, $translator_level);
        $job_ids = collect($job_ids)->filter(function($job) use($cuser) {
            $jobuserid = $job->user_id;
            $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
            $checktown = Job::checkTowns($jobuserid, $cuser->id);

            if($job->specific_job == 'SpecificJob' && $job->check_particular_job == 'userCanNotAcceptJob') {
                return false;
            }
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                return false;
            }
            return true;
        });

        return $job_ids;
    }

    public function customerNotCall($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'not_carried_out_customer';

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $tr->completed_at = $completeddate;
        $tr->completed_by = $tr->user_id;
        $job->save();
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    public function ignoreExpiring($id)
    {
        $job = Job::findOrFail($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }

    public function reopen($request)
    {
        $jobid = $request['jobid'];
        $userid = $request['userid'];

        $job = Job::find($jobid);
        $job = $job->toArray();

        $data = array();
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['will_expire_at'] = TeHelper::willExpireAt($job['due'], $data['created_at']);
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['user_id'] = $userid;
        $data['job_id'] = $jobid;
        $data['cancel_at'] = Carbon::now();

        $datareopen = array();
        $datareopen['status'] = 'pending';
        $datareopen['created_at'] = Carbon::now();
        $datareopen['will_expire_at'] = TeHelper::willExpireAt($job['due'], $datareopen['created_at']);
        //$datareopen['updated_at'] = date('Y-m-d H:i:s');

//        $this->logger->addInfo('USER #' . Auth::user()->id . ' reopen booking #: ' . $jobid);

        if ($job['status'] != 'timedout') {
            $affectedRows = Job::where('id', '=', $jobid)->update($datareopen);
            $new_jobid = $jobid;
        } else {
            $job['status'] = 'pending';
            $job['created_at'] = Carbon::now();
            $job['updated_at'] = Carbon::now();
            $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s'));
            $job['updated_at'] = date('Y-m-d H:i:s');
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobid;
            //$job[0]['user_email'] = $user_email;
            $affectedRows = Job::create($job);
            $new_jobid = $affectedRows['id'];
        }
        //$result = DB::table('translator_job_rel')->insertGetId($data);
        Translator::where('job_id', $jobid)->where('cancel_at', NULL)->update(['cancel_at' => $data['cancel_at']]);
        Translator::create($data);
        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($new_jobid);
            return ["Tolk cancelled!"];
        }
        return ["Please try again!"];
    }

    public function sendNotificationTranslator($job, array $job_data, $exclude_user_id)
    {
        $this->pushNotificationsService->sendNotificationTranslator($job, $job_data, $exclude_user_id);
    }

    /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        // prepare message templates
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);

        $physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);

        // analyse weather it's phone or physical; if both = default to phone
        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
            // It's a physical job
            $message = $physicalJobMessageTemplate;
        } else if ($job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes') {
            // It's a phone job
            $message = $phoneJobMessageTemplate;
        } else if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'yes') {
            // It's both, but should be handled as phone job
            $message = $phoneJobMessageTemplate;
        } else {
            // This shouldn't be feasible, so no handling of this edge case
            $message = '';
        }
        Log::info($message);

        // send messages via sms handler
        foreach ($translators as $translator) {
            // send message to translator
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time
     * @param  string $format
     * @return string
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);

        return sprintf($format, $hours, $minutes);
    }
}