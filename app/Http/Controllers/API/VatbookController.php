<?php

namespace App\Http\Controllers\API;

use App;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vatbook;
use App\Models\Position;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Collection;
use anlutro\LaravelSettings\Facade as Setting;
use App\Http\Controllers\ActivityLogController;

class VatbookController extends Controller
{
    /*public function __construct()
    {
        $this->middleware('client');
    }*/

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $bookings = Vatbook::where('deleted', false)->get()->sortBy('time_start');

        return response()->json([
            'bookings' => $bookings
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'cid' => 'required|integer',
            'date' => 'required|date_format:d/m/Y|after_or_equal:today',
            'start_at' => 'required|date_format:H:i',
            'end_at' => 'required|date_format:H:i',
            'position' => 'required|exists:positions,callsign',
            'tag' => 'nullable|integer|between:1,3'
        ]);

        $user = User::findorFail($request['cid']);
        $booking = new Vatbook();
        $date = Carbon::createFromFormat('d/m/Y', $data['date']);
        $booking->time_start = Carbon::createFromFormat('H:i', $data['start_at'])->setDateFrom($date);
        $booking->time_end = Carbon::createFromFormat('H:i', $data['end_at'])->setDateFrom($date);

        $booking->local_id = floor($user->id / (date('z') + 1));
        $booking->callsign = strtoupper($data['position']);
        $booking->position_id = Position::all()->firstWhere('callsign', strtoupper($data['position']))->id;
        $booking->name = $user->name;
        $booking->cid = $user->id;
        $booking->user_id = $user->id;

        if ($booking->time_start === $booking->time_end) {
            return response()->json([
                'error' => 'Start and end time cannot be the same'
            ], 400);
        }

        if ($booking->time_start->diffInMinutes($booking->time_end, false) < 0) {
            $booking->time_end->addDay();
        }

        if ($booking->time_start->diffInMinutes(Carbon::now(), false) > 0) {
            return response()->json([
                'error' => 'Start time cannot be in the past'
            ], 400);
        }

        if (!Vatbook::whereBetween('time_start', [$booking->time_start, $booking->time_end])
            ->where('time_end', '!=', $booking->time_start)
            ->where('time_start', '!=', $booking->time_end)
            ->where('position_id', $booking->position_id)
            ->where('deleted', false)
            ->orWhereBetween('time_end', [$booking->time_start, $booking->time_end])
            ->where('time_end', '!=', $booking->time_start)
            ->where('time_start', '!=', $booking->time_end)
            ->where('position_id', $booking->position_id)
            ->where('deleted', false)
            ->get()->isEmpty()) {
            return response()->json([
                'error' => 'Booking overlaps with existing booking'
            ], 400);
        }

        $forcedTrainingTag = false;

        if ($booking->position->rating > $user->rating || $user->rating < 3) {
            $booking->training = 1;
            $forcedTrainingTag = true;
        } else if ($user->getActiveTraining() && $user->getActiveTraining()->isMaeTraining() && $booking->position->mae == true) {
            $booking->training = 1;
            $forcedTrainingTag = true;
        } else {
            $booking->training = 0;
        }

        if (isset($data['tag'])) {
            switch ($data['tag']) {
                case 1:
                    $booking->exam = 0;
                    $booking->event = 0;
                    $booking->training = 1;
                    break;
                case 2:
                    $booking->exam = 1;
                    $booking->event = 0;
                    $booking->training = 0;
                    break;
                case 3:
                    $booking->exam = 0;
                    $booking->event = 1;
                    $booking->training = 0;
                    break;
            }
        } else {
            $booking->exam = 0;
            $booking->event = 0;
        }

        if (App::environment('production')) {
            if ($booking->event) {
                $eventUrl = Setting::get('linkDomain');
                $response = file_get_contents(str_replace(' ', '%20', "http://vatbook.euroutepro.com/atc/insert.asp?Local_URL=noredir&Local_ID={$booking->local_id}&b_day={$date->format('d')}&b_month={$date->format('m')}&b_year={$date->format('Y')}&Controller={$booking->cid}&Position={$booking->callsign}&sTime={$booking->time_start->format('Hi')}&eTime={$booking->time_end->format('Hi')}&cid={$booking->cid}&T={$booking->training}&E={$booking->event}&E_URL={$eventUrl}&voice=1"));
            } else {
                $response = file_get_contents(str_replace(' ', '%20', "http://vatbook.euroutepro.com/atc/insert.asp?Local_URL=noredir&Local_ID={$booking->local_id}&b_day={$date->format('d')}&b_month={$date->format('m')}&b_year={$date->format('Y')}&Controller={$booking->cid}&Position={$booking->callsign}&sTime={$booking->time_start->format('Hi')}&eTime={$booking->time_end->format('Hi')}&cid={$booking->cid}&T={$booking->training}&E={$booking->event}&voice=1"));
            }

            preg_match_all('/EU_ID=(\d+)/', $response, $matches);
            $booking->eu_id = $matches[1][0];
        } else {
            $booking->eu_id = 0;
        }

        $booking->save();

        ActivityLogController::info('BOOKING', "Created vatbook booking" . $booking->id . " via API" .
            " ― from " . Carbon::parse($booking->time_start)->toEuropeanDateTime() .
            " → " . Carbon::parse($booking->time_end)->toEuropeanDateTime() .
            " ― Position: " . Position::find($booking->position_id)->callsign);

        if ($forcedTrainingTag) {
            return response()->json([
                'success' => 'Booking created',
                'booking' => $booking,
                'tag' => 'Training'
            ], 200);
        }

        return response()->json([
            'success' => 'Booking created',
            'booking' => $booking
        ], 200);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Vatbook  $vatbook
     * @return \Illuminate\Http\Response
     */
    public function show(Vatbook $vatbook)
    {
        $user = User::findorFail($vatbook->cid);
        $positions = new Collection();
        if ($user->rating >= 3) {
            $positions = Position::where('rating', '<=', $user->rating)->get();
        }

        if ($user->getActiveTraining(1)) {
            $positions = $positions->merge($user->getActiveTraining()->area->positions->where('rating', '<=', $user->getActiveTraining()->first()->vatsim_rating));
        }

        if ($user->isModeratorOrAbove()) {
            $positions = Position::all();
        }

        return response()->json([
            'booking' => $vatbook
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Vatbook  $vatbook
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Vatbook $vatbook)
    {
        $data = $request->validate([
            'cid' => 'required|integer',
            'date' => 'required|date_format:d/m/Y|after_or_equal:today',
            'start_at' => 'required|date_format:H:i',
            'end_at' => 'required|date_format:H:i',
            'position' => 'required|exists:positions,callsign',
            'tag' => 'nullable|integer|between:1,3'
        ]);

        $user = User::findorFail($data['cid']);

        $date = Carbon::createFromFormat('d/m/Y', $data['date']);
        $vatbook->time_start = Carbon::createFromFormat('H:i', $data['start_at'])->setDateFrom($date);
        $vatbook->time_end = Carbon::createFromFormat('H:i', $data['end_at'])->setDateFrom($date);

        $vatbook->callsign = strtoupper($data['position']);
        $vatbook->position_id = Position::all()->firstWhere('callsign', strtoupper($data['position']))->id;

        if ($vatbook->time_start === $vatbook->time_end) {
            return response()->json([
                'error' => 'Start and end time cannot be the same'
            ], 400);
        }

        if ($vatbook->time_start->diffInMinutes($vatbook->time_end, false) < 0) {
            $vatbook->time_end->addDay();
        }

        if ($vatbook->time_start->diffInMinutes(Carbon::now(), false) < 0) {
            return response()->json([
                'error' => 'Start time cannot be in the past'
            ], 400);
        }

        if (!Vatbook::whereBetween('time_start', [$vatbook->time_start, $vatbook->time_end])
            ->where('time_end', '!=', $vatbook->time_start)
            ->where('time_start', '!=', $vatbook->time_end)
            ->where('position_id', $vatbook->position_id)
            ->where('deleted', false)
            ->where('id', '!=', $vatbook->id)
            ->orWhereBetween('time_end', [$vatbook->time_start, $vatbook->time_end])
            ->where('time_end', '!=', $vatbook->time_start)
            ->where('time_start', '!=', $vatbook->time_end)
            ->where('position_id', $vatbook->position_id)
            ->where('deleted', false)
            ->where('id', '!=', $vatbook->id)
            ->get()->isEmpty()) {
            return response()->json([
                'error' => 'Booking overlaps with another booking'
            ], 400);
        }

        $forcedTrainingTag = false;

        if (($vatbook->position->rating > $user->rating || $user->rating < 3) && !$user->isModeratorOrAbove()) {
            $vatbook->training = 1;
            $forcedTrainingTag = true;
        } else if ($user->getActiveTraining() && $user->getActiveTraining()->isMaeTraining() && $vatbook->position->mae == true) {
            $vatbook->training = 1;
            $forcedTrainingTag = true;
        } else {
            $vatbook->training = 0;
        }

        if (isset($data['tag'])) {
            switch ($data['tag']) {
                case 1:
                    $vatbook->exam = 0;
                    $vatbook->event = 0;
                    $vatbook->training = 1;
                    break;
                case 2:
                    $vatbook->exam = 1;
                    $vatbook->event = 0;
                    $vatbook->training = 0;
                    break;
                case 3:
                    $vatbook->training = 0;
                    $vatbook->exam = 0;
                    $vatbook->event = 1;
                    break;
            }
        } else {
            $vatbook->exam = 0;
            $vatbook->event = 0;
        }

        if (App::environment('production')) {
            if ($vatbook->event) {
                $eventUrl = Setting::get('linkDomain');
                file_get_contents(str_replace(' ', '%20', "http://vatbook.euroutepro.com/atc/update.asp?Local_URL=noredir&EU_ID={$vatbook->eu_id}&Local_ID={$vatbook->local_id}&b_day={$date->format('d')}&b_month={$date->format('m')}&b_year={$date->format('Y')}&Controller={$vatbook->cid}&Position={$vatbook->callsign}&sTime={$vatbook->time_start->format('Hi')}&eTime={$vatbook->time_end->format('Hi')}&cid={$vatbook->cid}&T={$vatbook->training}&E={$vatbook->event}&E_URL={$eventUrl}&voice=1"));
            } else {
                file_get_contents(str_replace(' ', '%20',"http://vatbook.euroutepro.com/atc/update.asp?Local_URL=noredir&EU_ID={$vatbook->eu_id}&Local_ID={$vatbook->local_id}&b_day={$date->format('d')}&b_month={$date->format('m')}&b_year={$date->format('Y')}&Controller={$vatbook->cid}&Position={$vatbook->callsign}&sTime={$vatbook->time_start->format('Hi')}&eTime={$vatbook->time_end->format('Hi')}&cid={$vatbook->cid}&T={$vatbook->training}&E={$vatbook->event}&voice=1"));
            }
        }

        $vatbook->save();

        ActivityLogController::info('BOOKING', "Updated vatbook booking ".$vatbook->id. " via API".
        " ― from ".Carbon::parse($vatbook->time_start)->toEuropeanDateTime().
        " → ".Carbon::parse($vatbook->time_end)->toEuropeanDateTime().
        " ― Position: ".Position::find($vatbook->position_id)->callsign);

        if($forcedTrainingTag){
            return response()->json([
                'message' => 'Booking updated',
                'booking' => $vatbook,
                'tag' => 'Training'
            ], 200);
        }

        return response()->json([
            'message' => 'Booking updated',
            'booking' => $vatbook
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Vatbook  $vatbook
     * @return \Illuminate\Http\Response
     */
    public function destroy(Vatbook $vatbook)
    {
        if(App::environement('production')) {
            file_get_contents('http://vatbook.euroutepro.com/atc/delete.asp?Local_URL=noredir&EU_ID=' . $vatbook->eu_id . '&Local_ID=' . $vatbook->local_id);
        }

        $vatbook->deleted = true;
        $vatbook->local_id = null;
        $vatbook->save();

        ActivityLogController::warning('BOOKING', "Deleted vatbook booking ".$vatbook->id. " via API".
        " ― from ".Carbon::parse($vatbook->time_start)->toEuropeanDateTime().
        " → ".Carbon::parse($vatbook->time_end)->toEuropeanDateTime().
        " ― Position: ".Position::find($vatbook->position_id)->callsign);

        return response()->json([
            'message' => 'Booking deleted',
            'booking' => $vatbook
        ], 200);
    }
}
