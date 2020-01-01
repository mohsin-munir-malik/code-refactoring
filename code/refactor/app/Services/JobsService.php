<?php

namespace App\Services;

class JobsService
{
    public function getCustomerJobs(User $customer)
    {
        return $customer->jobs()
            ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
            ->whereIn('status', ['pending', 'assigned', 'started'])
            ->orderBy('due', 'asc')
            ->get();
    }

    public function getTranslatorJobs(User $translator)
    {
        return Job::getTranslatorJobs($translator->id, 'new')
            ->pluck('jobs')
            ->all();
    }
}