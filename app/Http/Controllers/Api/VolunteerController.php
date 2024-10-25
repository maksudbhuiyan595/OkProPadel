<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Volunteer;
use File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VolunteerController extends Controller
{
    public function updateVolunterRole(Request $request,$id)
    {
        $validator = Validator::make($request->all(), [
            "role" => "required|string",
        ]);

        if ($validator->fails()) {
            return $this->sendError("Validation Errors", $validator->errors());
        }
        $volunter = Volunteer::find($id);
        if(!$volunter){
            return $this->sendError("Not found volunter.");
        }
        $volunter->role = $request->role;
        $volunter->save();
        return $this->sendResponse([],"Role successfully updated.");

    }
    public function index(Request $request)
    {
        $volunteers = Volunteer::where('status', true)->orderBy('id', 'desc')->paginate(10);
        if ($volunteers->isEmpty()) {
            return $this->sendError('No Volunteer Found.');
        }
        return $this->sendResponse([
            'data' => $volunteers->items(),
            'meta' => [
                'current_page' => $volunteers->currentPage(),
                'total_pages' => $volunteers->lastPage(),
                'total_volunteers' => $volunteers->total(),
                'per_page' => $volunteers->perPage(),
            ],
        ], 'All Volunteers retrieved successfully.');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:volunteers,email',
            'location' => 'required|string|max:255',
            'level' => 'required|in:1,2,3,4,5',
            'role' => 'required|string|max:255',
            'phone_number' => 'nullable|string|max:20',
            'image' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'status' => 'boolean',
        ]);
        if ($validator->fails()) {
            return $this->sendError("Validation Error:", $validator->errors());
        }
        $imagePath = null;
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imagePath = 'uploads/volunteers/' . time() . '.' . $image->getClientOriginalName();
            $image->move(public_path('uploads/volunteers'), $imagePath);
        }
        $volunteer = Volunteer::create([
            'name' => $request->name,
            'email' => $request->email,
            'location' => $request->location,
            'level' => $request->level,
            'role' => $request->role,
            'phone_number' => $request->phone_number,
            'image' => $imagePath,
            'status' => true,
        ]);

        return $this->sendResponse($volunteer, "Volunteer created successfully.");
    }
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:volunteers,email,' . $id,
            'location' => 'sometimes|required|string|max:255',
            'level' => 'sometimes|required|in:1,2,3,4,5',
            'role' => 'sometimes|required|string|max:255',
            'phone_number' => 'nullable|string|max:20',
            'image' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'status' => 'boolean',
        ]);
        if ($validator->fails()) {
            return $this->sendError("Validation Error:", $validator->errors());
        }
        $volunteer = Volunteer::findOrFail($id);
        if ($request->hasFile('image')) {
            if ($volunteer->image) {
                $oldImagePath = public_path($volunteer->image);
                if (File::exists($oldImagePath)) {
                    File::delete($oldImagePath);
                }
            }
            $image = $request->file('image');
            $imagePath = 'uploads/volunteers/' . time() . '.' . $image->getClientOriginalExtension();
            $image->move(public_path('uploads/volunteers'), $imagePath);
        }
        $volunteer->name = $request->name ?? $volunteer->name;
        $volunteer->email = $request->email ?? $volunteer->email;
        $volunteer->location = $request->location ?? $volunteer->location;
        $volunteer->level = $request->level ?? $volunteer->level;
        $volunteer->role = $request->role ?? $volunteer->role;
        $volunteer->image = $imagePath ?? $volunteer->image;
        $volunteer->status = $request->status ?? $volunteer->status;
       $volunteer->save();
        return $this->sendResponse($volunteer, "Volunteer updated successfully.");
    }
    public function delete($id)
    {
        $volunteer = Volunteer::findOrFail($id);
        if ($volunteer->image) {
            $oldImagePath = public_path($volunteer->image);
            if (File::exists($oldImagePath)) {
                File::delete($oldImagePath);
            }
        }
        $volunteer->delete();
        return $this->sendResponse(null, "Volunteer deleted successfully.");
    }
}
