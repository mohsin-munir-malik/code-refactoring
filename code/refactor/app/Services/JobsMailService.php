<?php


namespace App\Services;


use DTApi\Helpers\TeHelper;

trait JobsMailService
{
    /**
     * @var AppMailer
     */
    private $mailer;

    public function __construct(MailerInterface $mailer)
    {
        $this->mailer = $mailer;
    }

    public function sendJobCreatedEmail($job)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $send_data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $user->name, $subject, 'emails.job-created', $send_data);
    }

    public function sendJobStatusChangedToCustEmail($job)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];
        $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
        $this->mailer->send($email, $user->name, $subject, 'emails.job-change-status-to-customer', $dataEmail);
    }

    public function sendJobCompletedEmail(Job $job)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $data);
    }

    public function sendSessionEndEmail($tr)
    {
        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $data);
    }

    public function changeStatusStartedEmail($job)
    {
        $user = $job->user()->first();
        $diff = explode(':', $job->session_time);
        $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';
        $email = $job->user_email ?: $user->email;
        $dataEmail = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $this->mailer->send($email, $user->name, $subject, 'emails.session-ended', $dataEmail);

        $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $dataEmail = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $this->mailer->send($user->user->email, $user->user->name, $subject, 'emails.session-ended', $dataEmail);
    }

    public function changeStatusPendingEmail($job, array $data, $changedTranslator)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] != 'assigned' || $changedTranslator) {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $user->name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            return;
        }

        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        $this->mailer->send($email, $user->name, $subject, 'emails.job-accepted', $dataEmail);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);
    }

    public function changeStatusAssignedEmail($job)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $this->mailer->send($email, $user->name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

        $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

        $email = $user->user->email;
        $name = $user->user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
    }

    public function sendJobAcceptedEmail($job)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $user->name, $subject, 'emails.job-accepted', $data);
    }

    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $user->name, $subject, 'emails.job-changed-translator-customer', $data);
        if ($current_translator) {
            $user = $current_translator->user;
            $data['user'] = $user;
            $this->mailer->send($user->email, $user->name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $new_translator->user;
        $data['user'] = $user;
        $this->mailer->send($user->email, $user->name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }

    /**
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($email, $user->name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            'user'     => $translator,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];
        $this->mailer->send($email, $user->name, $subject, 'emails.job-changed-lang', $data);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    public function sendJobEndedEmail($job)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $this->mailer->send($email, $user->name, $subject, 'emails.session-ended', $data);

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $user = $tr->user()->first();
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $this->mailer->send($user->email, $user->name, $subject, 'emails.session-ended', $data);
    }
}