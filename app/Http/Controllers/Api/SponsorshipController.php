<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SponsorshipProposalResource;
use App\Models\BrandCampaign;
use App\Models\SponsorshipProposal;
use App\Models\User;
use App\Support\DeveloperWebhookDispatcher;
use App\Support\SupportedLocales;
use App\Support\UserNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SponsorshipController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $scope = trim($request->string('scope')->toString());
        $proposals = SponsorshipProposal::query()
            ->with([
                'sender' => fn ($query) => $query->withProfileAggregates($request->user()),
                'recipient' => fn ($query) => $query->withProfileAggregates($request->user()),
                'brandCampaign',
            ])
            ->when($scope === 'sent', fn ($query) => $query->where('sender_id', $request->user()->id))
            ->when($scope === 'inbox', fn ($query) => $query->where('recipient_id', $request->user()->id))
            ->when(! in_array($scope, ['sent', 'inbox'], true), function ($query) use ($request): void {
                $query->where(function ($nested) use ($request): void {
                    $nested->where('sender_id', $request->user()->id)
                        ->orWhere('recipient_id', $request->user()->id);
                });
            })
            ->latest()
            ->get();

        return response()->json([
            'message' => __('messages.sponsorships.retrieved'),
            'data' => [
                'proposals' => SponsorshipProposalResource::collection($proposals),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validate([
            'recipientId' => ['required', 'exists:users,id'],
            'brandCampaignId' => ['nullable', 'exists:brand_campaigns,id'],
            'title' => ['required', 'string', 'max:255'],
            'brief' => ['nullable', 'string'],
            'feeAmount' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'deliverables' => ['nullable', 'array'],
            'proposedPublishAt' => ['nullable', 'date'],
        ]);

        abort_if((int) $validated['recipientId'] === $request->user()->id, 422, __('messages.sponsorships.cannot_send_to_self'));

        if (($validated['brandCampaignId'] ?? null) !== null) {
            $campaign = BrandCampaign::query()->findOrFail($validated['brandCampaignId']);
            abort_if($campaign->owner_id !== $request->user()->id, 403);
        }

        $proposal = SponsorshipProposal::query()->create([
            'sender_id' => $request->user()->id,
            'recipient_id' => (int) $validated['recipientId'],
            'brand_campaign_id' => $validated['brandCampaignId'] ?? null,
            'title' => $validated['title'],
            'brief' => $validated['brief'] ?? null,
            'fee_amount' => (int) ($validated['feeAmount'] ?? 0),
            'currency' => strtoupper((string) ($validated['currency'] ?? 'NGN')),
            'status' => 'pending',
            'deliverables' => $validated['deliverables'] ?? [],
            'proposed_publish_at' => $validated['proposedPublishAt'] ?? null,
        ]);

        UserNotifier::sendTranslated(
            $proposal->recipient_id,
            $request->user()->id,
            'sponsorship_proposal_created',
            'messages.notifications.sponsorship_title',
            'messages.notifications.sponsorship_body',
            ['name' => $request->user()->name, 'title' => $proposal->title],
            ['sponsorshipProposalId' => $proposal->id],
        );

        $recipient = User::query()->findOrFail($proposal->recipient_id);
        DeveloperWebhookDispatcher::dispatch($recipient, 'sponsorship.proposal.created', [
            'type' => 'sponsorship.proposal.created',
            'proposalId' => $proposal->id,
            'status' => $proposal->status,
        ]);

        $proposal->load([
            'sender' => fn ($query) => $query->withProfileAggregates($request->user()),
            'recipient' => fn ($query) => $query->withProfileAggregates($request->user()),
            'brandCampaign',
        ]);

        return response()->json([
            'message' => __('messages.sponsorships.created'),
            'data' => [
                'proposal' => new SponsorshipProposalResource($proposal),
            ],
        ], 201);
    }

    public function update(Request $request, SponsorshipProposal $sponsorshipProposal): JsonResponse
    {
        SupportedLocales::apply($request);

        abort_unless(
            in_array($request->user()->id, [$sponsorshipProposal->sender_id, $sponsorshipProposal->recipient_id], true),
            403,
        );

        $validated = $request->validate([
            'action' => ['required', Rule::in(['accept', 'reject', 'cancel'])],
        ]);

        $action = $validated['action'];
        if (in_array($action, ['accept', 'reject'], true)) {
            abort_if($request->user()->id !== $sponsorshipProposal->recipient_id, 403);
        }

        if ($action === 'cancel') {
            abort_if($request->user()->id !== $sponsorshipProposal->sender_id, 403);
        }

        $sponsorshipProposal->forceFill([
            'status' => match ($action) {
                'accept' => 'accepted',
                'reject' => 'rejected',
                default => 'cancelled',
            },
            'responded_at' => now(),
        ])->save();

        $otherPartyId = $request->user()->id === $sponsorshipProposal->sender_id
            ? $sponsorshipProposal->recipient_id
            : $sponsorshipProposal->sender_id;

        UserNotifier::sendTranslated(
            $otherPartyId,
            $request->user()->id,
            'sponsorship_proposal_updated',
            'messages.notifications.sponsorship_title',
            'messages.notifications.sponsorship_updated_body',
            ['status' => $sponsorshipProposal->status, 'title' => $sponsorshipProposal->title],
            ['sponsorshipProposalId' => $sponsorshipProposal->id, 'status' => $sponsorshipProposal->status],
        );

        $otherParty = User::query()->findOrFail($otherPartyId);
        DeveloperWebhookDispatcher::dispatch($otherParty, 'sponsorship.proposal.updated', [
            'type' => 'sponsorship.proposal.updated',
            'proposalId' => $sponsorshipProposal->id,
            'status' => $sponsorshipProposal->status,
            'actorId' => $request->user()->id,
        ]);

        $sponsorshipProposal->load([
            'sender' => fn ($query) => $query->withProfileAggregates($request->user()),
            'recipient' => fn ($query) => $query->withProfileAggregates($request->user()),
            'brandCampaign',
        ]);

        return response()->json([
            'message' => __('messages.sponsorships.updated'),
            'data' => [
                'proposal' => new SponsorshipProposalResource($sponsorshipProposal),
            ],
        ]);
    }
}