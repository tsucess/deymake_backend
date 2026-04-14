<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fullName' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'avatarUrl' => $this->avatar_url,
            'bio' => $this->bio,
            'isAdmin' => (bool) $this->is_admin,
            'accountStatus' => $this->accountStatus(),
            'accountStatusNotes' => $this->account_status_notes,
            'isSuspended' => $this->isSuspended(),
            'suspendedAt' => $this->suspended_at?->toISOString(),
            'lastActiveAt' => $this->last_active_at?->toISOString(),
            'isOnline' => $this->isActiveNow(),
            'isVerifiedCreator' => $this->creator_verification_status === 'approved',
            'creatorVerificationStatus' => $this->creator_verification_status ?: 'unsubmitted',
            'creatorVerifiedAt' => $this->creator_verified_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
            'stats' => [
                'videosCount' => (int) ($this->videos_count ?? 0),
                'publishedVideosCount' => (int) ($this->published_videos_count ?? 0),
                'liveVideosCount' => (int) ($this->live_videos_count ?? 0),
                'subscribersCount' => (int) ($this->subscribers_count ?? 0),
                'reportsSubmittedCount' => (int) ($this->video_reports_count ?? 0),
                'challengeSubmissionsCount' => (int) ($this->challenge_submissions_count ?? 0),
            ],
        ];
    }
}