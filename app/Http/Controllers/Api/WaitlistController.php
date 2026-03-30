<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Waitlist\StoreWaitlistRequest;
use App\Http\Resources\WaitlistEntryResource;
use App\Models\WaitlistEntry;
use Illuminate\Http\JsonResponse;

class WaitlistController extends Controller
{
    public function store(StoreWaitlistRequest $request): JsonResponse
    {
        $waitlistEntry = WaitlistEntry::create([
            'full_name' => $request->string('firstName')->toString(),
            'email' => $request->string('email')->toString(),
            'phone' => $request->input('phone'),
            'country' => $request->string('country')->toString(),
            'describes' => $request->string('describes')->toString(),
            'love_to_see' => $request->input('loveToSee'),
            'agreed_to_contact' => $request->boolean('agreed'),
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => __('messages.waitlist.added'),
            'data' => [
                'waitlistEntry' => new WaitlistEntryResource($waitlistEntry),
            ],
        ], 201);
    }
}