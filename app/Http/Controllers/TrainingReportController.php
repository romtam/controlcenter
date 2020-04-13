<?php

namespace App\Http\Controllers;

use App\Training;
use App\TrainingReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TrainingReportController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @param Training $training
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function index(Training $training)
    {
        $this->authorize('viewReports', $training);

        $reports = TrainingReport::where('training_id', $training->id);

        foreach ($reports as $key => $report) {
            if ( ! Auth::user()->can('view', $report)) {
                $reports->pull($key);
            }
        }

        return view('trainingReport.index', compact('reports'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @param Training $training
     * @return void
     */
    public function create(Training $training)
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param Training $training
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function store(Request $request, Training $training)
    {
        $this->authorize('create', TrainingReport::class);

        $data = $this->validateRequest();
        $data['written_by_id'] = Auth::id();
        $data['training_id'] = $training->id;

        $report = TrainingReport::create($data);

        return redirect($report->path());
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\TrainingReport  $trainingReport
     * @return \Illuminate\Http\Response
     */
    public function show(TrainingReport $trainingReport)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\TrainingReport  $trainingReport
     * @return \Illuminate\Http\Response
     */
    public function edit(TrainingReport $trainingReport)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\TrainingReport  $trainingReport
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, TrainingReport $trainingReport)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\TrainingReport  $trainingReport
     * @return \Illuminate\Http\Response
     */
    public function destroy(TrainingReport $trainingReport)
    {
        //
    }

    /**
     * Validates the request data
     */
    protected function validateRequest()
    {
        return request()->validate([
            'content' => 'required',
            'mentor_notes' => 'nullable',
            'position' => 'nullable',
            'draft' => 'sometimes|required|boolean'
        ]);
    }
}
