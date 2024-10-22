<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\PadelMatch;
use App\Models\PadelMatchMember;
use App\Models\RequestTrailMacth;
use App\Models\TrailMatch;
use App\Models\User;
use App\Models\Volunteer;
use App\Notifications\TrailMatchRequestNotification;
use App\Notifications\TrailMatchStatusNotification;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{
    public function TrailMatchStatus(Request $request)
    {
        $request->validate([
            'trail_match_id' => 'required|exists:trail_matches,id',
        ]);
        $trailMatch = TrailMatch::find($request->trail_match_id);
        if (!$trailMatch) {
            return $this->sendError('No trail match found.');
        }
        if (now()->isAfter($trailMatch->date)) {
            $trailMatch->status = false;
            $trailMatch->save();
        }
        return $this->sendResponse(['status'=>$trailMatch->status],'Status get successfully.');
    }
    public function acceptTrailMatch(Request $request)
    {
        $request->validate([
            'trail_match_id' => 'required|exists:trail_matches,id',
        ]);
        $trailMatch = TrailMatch::find($request->trail_match_id);
        $trailMatch->status = true;
        $trailMatch->save();
        $volunteerIds = json_decode($trailMatch->volunteer_id, true);
        $this->notifyUsers($trailMatch->user_id, $volunteerIds, 'Trail Match Accepted', "{$trailMatch->user->full_name} has accepted the trail match.");
        return $this->sendResponse([], 'Trail match accepted successfully.');
    }
    public function denyTrailMatch(Request $request)
    {
        $request->validate([
            'trail_match_id' => 'required|exists:trail_matches,id',
        ]);
        $trailMatch = TrailMatch::find($request->trail_match_id);
        $trailMatch->status = false;
        $trailMatch->save();
        $volunteerIds = json_decode($trailMatch->volunteer_id, true);
        $this->notifyUsers($trailMatch->user_id, $volunteerIds, 'Trail Match Denied', "{$trailMatch->user->full_name} has denied the trail match.");
        return $this->sendResponse([], 'Trail match denied successfully.');
    }
    private function notifyUsers($userId, $volunteerIds, $title, $message)
    {
        $user = User::find($userId);
        if ($user) {
            $user->notify(new TrailMatchStatusNotification($title, $message,$user));
        }
        if (is_array($volunteerIds) && count($volunteerIds) > 0) {
            $volunteers = Volunteer::whereIn('id', $volunteerIds)->get();
            foreach ($volunteers as $volunteer) {
                $volunteer->notify(new TrailMatchStatusNotification($title, $message, $user));
            }
        }
    }
    public function TrailMatchDetails()
    {
        $user = Auth::user();
        $trailMatches = TrailMatch::where('user_id', $user->id)->get();
        if ($trailMatches->isEmpty()) {
            return $this->sendError('No trail matches found.', [], 404);
        }
        $formattedTrailMatches = $trailMatches->map(function ($trailMatch) {
            $volunteerIds = json_decode($trailMatch->volunteer_id);
            $volunteers = Volunteer::whereIn('id', $volunteerIds)->get()->map(function ($volunteer) {
                return [
                    'id' => $volunteer->id,
                    'name' => $volunteer->name,
                    'image' => $volunteer->image
                        ? url('uploads/volunteers', $volunteer->image)
                        : null,
                ];
            });
            $user = $trailMatch->user;
            $club = $trailMatch->club;
            return [
                'trail_match_id' => $trailMatch->id,
                'full_name' => $user->full_name,
                'image' => $user->image
                    ? url('Profile/', $user->image)
                    : null,
                'level' => $user->level,
                'level_name' => $user->level_name,
                'club_name' => $club ? $club->club_name : 'No club',
                'club_location' => $club ? $club->location : 'No location',
                'time' => $trailMatch->time,
                'date' => $trailMatch->date,
                'volunteers' => $volunteers,
                'created_at' => $trailMatch->created_at->format('Y-m-d H:i:s'),
            ];
        });
        return $this->sendResponse($formattedTrailMatches, 'Trail matches retrieved successfully.');
    }
    public function getRequestToTrailMatch()
    {
        $user = Auth::user();
        $requestTrailMatch = RequestTrailMacth::where("user_id", $user->id)
            ->where("status", "request")
            ->orderBy('id', 'desc')
            ->first();
        if (!$requestTrailMatch) {
            return $this->sendError("No request found.", [], 404);
        }
        return $this->sendResponse($requestTrailMatch, 'Request retrieved successfully.');
    }
    public function requestToTrailMatch(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "request_level"=> "required",
        ]);
        if ($validator->fails())
        {
            return $this->sendError("Validaiton Errors", $validator->errors());
        }
        $user = Auth::user();
        if(!$user)
        {
            return $this->sendError("You are not authenticate.");
        }
       $request= RequestTrailMacth::create([
            "user_id"=> $user->id,
            "request_level"=> $request->request_level,
            "status"=> 'request',
        ]);
        $admin = User::where('role', 'ADMIN')->first();

        $admin->notify(new TrailMatchRequestNotification($user));

        return $this->sendResponse($request,'Request send successfully.');
    }
    public function myProfile()
    {
        $user = Auth::user();
        if(!$user)
        {
            return $this->sendError("User not found.");
        }
        return $this->getFormattedUserDetails($user);
    }
    public function anotherUserProfile($id)
    {
        $user = User::findOrFail($id);
        if(!$user)
        {
            return $this->sendError("User not found");
        }
        return $this->getFormattedUserDetails($user);
    }
    private function getFormattedUserDetails($user)
    {
        $client = new Client();
        $createdMatches = PadelMatch::where('creator_id', $user->id)->get();
        $joinedMatches = PadelMatchMember::where('user_id', $user->id)
            ->with('padelMatch')
            ->get();
        $formattedUser = [
            'id' => $user->id,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'level' => $user->level,
            'matches_played'=> $user->matches_played,
            'created_matches_count' => $createdMatches->count(),
            'joined_matches_count' => $joinedMatches->count(),
            'created_matches' => $createdMatches->map(function ($match) use ($client) {
                $location = $this->getLocationFromCoordinates($client, $match->latitude, $match->longitude, env('GOOGLE_MAPS_API_KEY'));
                $group = Group::where('match_id', $match->id)->first();
                $playerCount = $group ? $group->members()->count() : 0;
                $join = $playerCount < 8;

                return [
                    'id' => $match->id,
                    'mind_text' => $match->mind_text,
                    'selected_level' => $match->selected_level,
                    'level' => $match->level,
                    'level_name' => $match->level_name,
                    'location_address' => $location,
                    'player_count' => $playerCount,
                    'join'=> $join,
                    'created_at' => $match->created_at->toDateTimeString(),

                ];
            }),

            'joined_matches' => $joinedMatches->map(function ($member) use ($client) {
                $match = $member->padelMatch;
                $location = $this->getLocationFromCoordinates($client, $match->latitude, $match->longitude, env('GOOGLE_MAPS_API_KEY'));
                $group = Group::where('match_id', $match->id)->first();
                $playerCount = $group ? $group->members()->count() : 0;
                $join = $playerCount < 8;
                return [
                    'id' => $match->id,
                    'mind_text' => $match->mind_text,
                    'selected_level' => $match->selected_level,
                    'level' => $match->level,
                    'level_name' => $match->level_name,
                    'location_address' => $location,
                    'player_count' => $playerCount,
                    'join' => $join,
                    'created_at' => $match->created_at->toDateTimeString(),

                ];
            }),
        ];
        return response()->json([
            'success' => true,
            'data' => $formattedUser,
            'message' => 'User profile retrieved successfully.'
        ], 200);
    }

    private function getLocationFromCoordinates($client, $latitude, $longitude, $apiKey)
    {
        try {
            $url = "https://maps.googleapis.com/maps/api/geocode/json?latlng={$latitude},{$longitude}&key={$apiKey}";
            $response = $client->get($url);
            $data = json_decode($response->getBody(), true);

            if (isset($data['results']) && count($data['results']) > 0) {
                return $data['results'][0]['formatted_address'];
            } else {
                return 'Location not found';
            }
        } catch (RequestException $e) {
            return 'Error retrieving location: ' . $e->getMessage();
        }
    }
    public function upgradeLevelFree()
    {
        $user = Auth::user();
        $currentLevel = $user->level;
        $levelNames = [
            1 => 'Beginner',
            2 => 'Lower-Intermediate',
            3 => 'Upper-Intermediate',
            4 => 'Advanced',
            5 => 'Professional',
        ];
        $beforeLevelArray = [];
        for ($i = 1; $i < $currentLevel; $i++) {
            $beforeLevelArray[] = [
                'level' => $i,
                'level_name' => $levelNames[$i]
            ];
        }
        $currentLevelDetails = [
            'level' => $currentLevel,
            'level_name' => $levelNames[$currentLevel] ?? 'Unknown'
        ];
        $nextLevelDetails = ($currentLevel < 5) ? [
            'level' => $currentLevel + 1,
            'level_name' => $levelNames[$currentLevel + 1]
        ] : null;
        $formattedResponse = [
            'success' => true,
            'data' => [
                'user_id' => $user->id,
                'full_name' => $user->full_name,
                'user_name' => $user->user_name,
                'current_level' => $currentLevelDetails,
                'before_levels' => ($currentLevel !== 1) ? ($currentLevel - 1) : null,
                'before_level_array' => $beforeLevelArray,
                'after_levels' => ($currentLevel < 5) ? ($currentLevel + 1) : null,
                'after_level_array' => $nextLevelDetails
            ],
            'message' => 'User level upgraded successfully.'
        ];
        if ($currentLevel === 5) {
            unset($formattedResponse['data']['before_levels']);
            unset($formattedResponse['data']['before_level_array']);
        }
        return response()->json($formattedResponse, 200);
    }

}