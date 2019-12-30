<?php


namespace App\Services;


use DTApi\Helpers\TeHelper;

trait JobsPaginator
{
    private function getAllJobsForAdmin($requestdata)
    {
        $allJobs = Job::query();

        if (array_get($requestdata, 'feedback') != 'false') {
            $allJobs->where('ignore_feedback', '0');
            $allJobs->whereHas('feedback', function ($q) {
                $q->where('rating', '<=', '3');
            });
            if (array_get($requestdata, 'count') != 'false')
                return ['count' => $allJobs->count()];
        }

        if (isset($requestdata['id']) && $requestdata['id'] != '') {
            $allJobs->whereIn('id', array_wrap($requestdata['id']));
            $requestdata = array_only($requestdata, ['id']);
        }

        if (array_get($requestdata, 'lang')) {
            $allJobs->whereIn('from_language_id', $requestdata['lang']);
        }
        if (array_get($requestdata, 'status')) {
            $allJobs->whereIn('status', $requestdata['status']);
        }
        if (array_get($requestdata, 'expired_at')) {
            $allJobs->where('expired_at', '>=', $requestdata['expired_at']);
        }
        if (array_get($requestdata, 'will_expire_at')) {
            $allJobs->where('will_expire_at', '>=', $requestdata['will_expire_at']);
        }
        if (array_get($requestdata, 'customer_email') && $requestdata['customer_email']) {
            $users = DB::table('users')->whereIn('email', $requestdata['customer_email'])->get();
            if ($users) {
                $allJobs->whereIn('user_id', collect($users)->pluck('id')->all());
            }
        }
        if (array_get($requestdata, 'translator_email')) {
            $users = DB::table('users')->whereIn('email', $requestdata['translator_email'])->get();
            if ($users) {
                $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->lists('job_id');
                $allJobs->whereIn('id', $allJobIDs);
            }
        }
        $timetype = array_get($requestdata, 'filter_timetype');
        if (in_array($timetype, ['created', 'due'])) {
            if (array_get($requestdata, 'from')) {
                $allJobs->where($timetype, '>=', $requestdata["from"]);
            }
            if (array_get($requestdata, 'to')) {
                $to = $requestdata["to"] . " 23:59:00";
                $allJobs->where($timetype, '<=', $to);
            }
            $allJobs->orderBy($timetype, 'desc');
        }

        if (array_get($requestdata, 'job_type')) {
            $allJobs->whereIn('job_type', $requestdata['job_type']);
        }

        if (isset($requestdata['physical'])) {
            $allJobs->where('customer_physical_type', $requestdata['physical']);
            $allJobs->where('ignore_physical', 0);
        }

        if (isset($requestdata['phone'])) {
            $allJobs->where('customer_phone_type', $requestdata['phone']);
            if(isset($requestdata['physical']))
                $allJobs->where('ignore_physical_phone', 0);
        }

        if (isset($requestdata['flagged'])) {
            $allJobs->where('flagged', $requestdata['flagged']);
            $allJobs->where('ignore_flagged', 0);
        }

        if (isset($requestdata['distance']) && $requestdata['distance'] == 'empty') {
            $allJobs->whereDoesntHave('distance');
        }

        if(isset($requestdata['salary']) &&  $requestdata['salary'] == 'yes') {
            $allJobs->whereDoesntHave('user.salaries');
        }

        if (array_get($requestdata, 'count') == 'true') {
            return ['count' => $allJobs->count()];
        }

        if (array_get($requestdata, 'consumer_type')) {
            $allJobs->whereHas('user.userMeta', function($q) use ($requestdata) {
                $q->where('consumer_type', $requestdata['consumer_type']);
            });
        }

        if ($bookingType = array_get($requestdata, 'booking_type')) {
            if ($bookingType == 'physical')
                $allJobs->where('customer_physical_type', 'yes');
            if ($bookingType == 'phone')
                $allJobs->where('customer_phone_type', 'yes');
        }

        $allJobs->orderBy('created_at', 'desc');
        $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

        return $allJobs;
    }

    public function getAllJobsForNonAdmins($requestdata, $consumer_type)
    {
        $allJobs = Job::query();

        $allJobs->where('job_type', '=', $consumer_type == 'RWS' ? 'rws' : 'unpaied');

        if (array_get($requestdata, 'id')) {
            $allJobs->where('id', $requestdata['id']);
            $requestdata = array_only($requestdata, ['id']);
        }

        if (array_get($requestdata, 'feedback') != 'false') {
            $allJobs->where('ignore_feedback', '0');
            $allJobs->whereHas('feedback', function($q) {
                $q->where('rating', '<=', '3');
            });
            if(array_get($requestdata, 'count') != 'false') {
                return ['count' => $allJobs->count()];
            }
        }

        if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
            $allJobs->whereIn('from_language_id', $requestdata['lang']);
        }
        if (isset($requestdata['status']) && $requestdata['status'] != '') {
            $allJobs->whereIn('status', $requestdata['status']);
        }
        if (array_get($requestdata, 'job_type')) {
            $allJobs->whereIn('job_type', $requestdata['job_type']);
        }
        if (array_get($requestdata, 'customer_email')) {
            $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
            if ($user) {
                $allJobs->where('user_id', '=', $user->id);
            }
        }
        $timetype = array_get($requestdata, 'filter_timetype');
        if (in_array($timetype, ['created', 'due'])) {
            if (array_get($requestdata, 'from')) {
                $allJobs->where($timetype, '>=', $requestdata["from"]);
            }
            if (array_get($requestdata, 'to')) {
                $to = $requestdata["to"] . " 23:59:00";
                $allJobs->where($timetype, '<=', $to);
            }
            $allJobs->orderBy($timetype, 'desc');
        }

        $allJobs->orderBy('created_at', 'desc');
        $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

        return $allJobs;
    }
    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumer_type = $cuser->consumer_type;

        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
            $allJobs = $this->getAllJobsForAdmin($requestdata);
        } else {
            $allJobs = $this->getAllJobsForNonAdmins($requestdata, $consumer_type);
        }
        return $allJobs = $limit == 'all' ? $allJobs->get() : $allJobs->paginate(15);
    }

    public function alerts()
    {
        $jobId = $this->jobsIdsBasedOnSession();

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');

        if ($cuser && $cuser->is('superadmin')) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')->whereIn('jobs.id', $jobId);
            if (array_get($requestdata, 'lang') != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (array_get($requestdata, 'status')) {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (array_get($requestdata, 'customer_email')) {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.ignore', 0);
                }
            }
            if (array_get($requestdata, 'translator_email')) {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.ignore', 0);
                }
            }
            if (array_get($requestdata, 'filter_timetype') == "created") {
                if (array_get($requestdata, 'from')) {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (array_get($requestdata, 'to')) {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (array_get($requestdata, 'filter_timetype') == "due") {
                if (array_get($requestdata, 'from')) {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (array_get($requestdata, 'to')) {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (array_get($requestdata, 'job_type')) {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.ignore', 0)
                ->whereIn('jobs.id', $jobId);

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    private function jobsIdsBasedOnSession()
    {
        return Job::all()->filter(function ($job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) < 3) {
                return false;
            }
            $diff = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);
            if ($diff < $job->duration || $diff < $job->duration * 2) {
                return false;
            }
            return true;
        })
            ->pluck('id')
            ->toArray();
    }

    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');

        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('ignore_expired', 0)
                ->where('jobs.due', '>=', Carbon::now());

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);

        }
        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

}