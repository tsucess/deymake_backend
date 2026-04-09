<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $participant = $this->participants->firstWhere('id', '!=', $request->user()?->id) ?? $this->participants->first();
        $lastMessage = $this->relationLoaded('latestMessage')
            ? $this->latestMessage
            : ($this->relationLoaded('messages') ? $this->messages->sortByDesc('created_at')->first() : null);
        $pivot = $request->user()
            ? $this->participants->firstWhere('id', $request->user()->id)?->pivot
            : null;
        $unreadCount = $this->resource->getAttribute('unread_count');

        if ($unreadCount === null) {
            $unreadCount = $request->user() && $this->relationLoaded('messages')
                ? $this->messages
                    ->where('user_id', '!=', $request->user()->id)
                    ->filter(fn ($message) => ! $pivot?->last_read_at || $message->created_at->gt($pivot->last_read_at))
                    ->count()
                : 0;
        }

        return [
            'id' => $this->id,
            'participant' => $participant ? new ProfileResource($participant) : null,
            'participants' => ProfileResource::collection($this->participants),
            'lastMessage' => $lastMessage ? new MessageResource($lastMessage->loadMissing('user')) : null,
            'unreadCount' => $unreadCount,
            'status' => $participant?->isActiveNow()
                ? __('messages.conversations.active_status')
                : ($lastMessage
                    ? __('messages.conversations.sent_prefix').' '.$lastMessage->created_at->diffForHumans()
                    : __('messages.conversations.no_messages_yet')),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}