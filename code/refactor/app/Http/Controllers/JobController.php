<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use DTApi\Repository\JobRepository;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class JobController extends Controller
{

    /**
     * @var JobRepository
     */
    protected $repository;

    /**
     * BookingController constructor.
     * @param JobRepository $jobRepository
     */
    public function __construct(JobRepository $jobRepository)
    {
        $this->repository = $jobRepository;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function acceptJob(Request $request)
    {
        $response = $this->repository->acceptJob($request->all(), $request->__authenticatedUser);

        return response($response);
    }

    public function acceptJobWithId(Request $request)
    {
        $response = $this->repository->acceptJobWithId($request->get('job_id'), $request->__authenticatedUser);

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request)
    {
        $response = $this->repository->cancelJobAjax($request->all(), $request->__authenticatedUser);

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function endJob(Request $request)
    {
        $response = $this->repository->endJob($request->all());

        return response($response);

    }
}
