<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CreatorPlanResource;
use App\Http\Resources\MembershipResource;
use App\Models\CreatorPlan;
use App\Models\Membership;
use App\Models\User;
use App\Services\WalletLedgerService;
use App\Support\DeveloperWebhookDispatcher;
use App\Support\UserNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MembershipController extends Controller
{
    private const BILLING_PERIODS = ['weekly', 'monthly', 'yearly'];
    private const STATUSES = ['active', 'cancelled'];

    public function creatorPlans(Request $request, User $user): JsonResponse
    {
        $viewer = auth('sanctum')->user() ?? $request->user();
        $membershipsByPlan = $viewer
            ? Membership::query()
                ->where('member_id', $viewer->id)
                ->get(['id', 'creator_plan_id', 'status'])
                ->keyBy('creator_plan_id')
            : collect();

        $plans = CreatorPlan::query()
            ->where('creator_id', $user->id)
            ->when(! $viewer?->is($user), fn ($query) => $query->where('is_active', true))
            ->withCount('memberships')
            ->withCount(['memberships as active_memberships_count' => fn ($query) => $query->where('status', 'active')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (CreatorPlan $plan) use ($membershipsByPlan) {
                $membership = $membershipsByPlan->get($plan->id);

                $plan->setAttribute('current_user_membership', $membership ? [
                    'id' => $membership->id,
                    'status' => $membership->status,
                ] : null);

                return $plan;
            });

        return response()->json([
            'message' => __('messages.memberships.plans_retrieved'),
            'data' => [
                'plans' => CreatorPlanResource::collection($plans),
            ],
        ]);
    }

    public function creatorDashboard(Request $request): JsonResponse
    {
        $memberships = Membership::query()
            ->where('creator_id', $request->user()->id)
            ->with(['plan', 'member' => fn ($query) => $query->withProfileAggregates($request->user())])
            ->latest()
            ->get();

        return response()->json([
            'message' => __('messages.memberships.creator_dashboard_retrieved'),
            'data' => [
                'memberships' => MembershipResource::collection($memberships),
            ],
        ]);
    }

    public function myMemberships(Request $request): JsonResponse
    {
        $memberships = Membership::query()
            ->where('member_id', $request->user()->id)
            ->with([
                'plan.creator' => fn ($query) => $query->withProfileAggregates($request->user()),
                'creator' => fn ($query) => $query->withProfileAggregates($request->user()),
            ])
            ->latest()
            ->get();

        return response()->json([
            'message' => __('messages.memberships.mine_retrieved'),
            'data' => [
                'memberships' => MembershipResource::collection($memberships),
            ],
        ]);
    }

    public function storePlan(Request $request): JsonResponse
    {
        $validated = $this->validatePlan($request);

        $plan = $request->user()->creatorPlans()->create($validated);
        $plan->loadCount('memberships');
        $plan->loadCount(['memberships as active_memberships_count' => fn ($query) => $query->where('status', 'active')]);

        DeveloperWebhookDispatcher::dispatch($request->user(), 'membership.plan.updated', [
            'type' => 'membership.plan.updated',
            'action' => 'created',
            'planId' => $plan->id,
        ]);

        return response()->json([
            'message' => __('messages.memberships.plan_created'),
            'data' => [
                'plan' => new CreatorPlanResource($plan),
            ],
        ], 201);
    }

    public function updatePlan(Request $request, CreatorPlan $plan): JsonResponse
    {
        abort_if($plan->creator_id !== $request->user()->id, 403);

        $validated = $this->validatePlan($request, true);
        $plan->forceFill($validated)->save();
        $plan->loadCount('memberships');
        $plan->loadCount(['memberships as active_memberships_count' => fn ($query) => $query->where('status', 'active')]);

        DeveloperWebhookDispatcher::dispatch($request->user(), 'membership.plan.updated', [
            'type' => 'membership.plan.updated',
            'action' => 'updated',
            'planId' => $plan->id,
        ]);

        return response()->json([
            'message' => __('messages.memberships.plan_updated'),
            'data' => [
                'plan' => new CreatorPlanResource($plan),
            ],
        ]);
    }

    public function destroyPlan(Request $request, CreatorPlan $plan): JsonResponse
    {
        abort_if($plan->creator_id !== $request->user()->id, 403);
        $planId = $plan->id;
        $plan->delete();

        DeveloperWebhookDispatcher::dispatch($request->user(), 'membership.plan.updated', [
            'type' => 'membership.plan.updated',
            'action' => 'deleted',
            'planId' => $planId,
        ]);

        return response()->json([
            'message' => __('messages.memberships.plan_deleted'),
        ]);
    }

    public function subscribe(Request $request, CreatorPlan $plan, WalletLedgerService $walletLedgerService): JsonResponse
    {
        abort_if($plan->creator_id === $request->user()->id, 422, __('messages.memberships.self_not_allowed'));
        abort_if(! $plan->is_active, 422, __('messages.memberships.plan_inactive'));

        $membership = Membership::query()->updateOrCreate(
            [
                'creator_plan_id' => $plan->id,
                'member_id' => $request->user()->id,
            ],
            [
                'creator_id' => $plan->creator_id,
                'status' => 'active',
                'price_amount' => $plan->price_amount,
                'currency' => $plan->currency,
                'billing_period' => $plan->billing_period,
                'started_at' => now(),
                'cancelled_at' => null,
                'ends_at' => null,
            ],
        );

        $membership->load([
            'plan',
            'creator' => fn ($query) => $query->withProfileAggregates($request->user()),
            'member' => fn ($query) => $query->withProfileAggregates($request->user()),
        ]);

        UserNotifier::sendTranslated(
            $plan->creator_id,
            $request->user()->id,
            'membership_created',
            'messages.notifications.membership_title',
            'messages.notifications.membership_body',
            ['name' => $request->user()->name, 'plan' => $plan->name],
            ['membershipId' => $membership->id, 'planId' => $plan->id],
        );

        DeveloperWebhookDispatcher::dispatch($membership->creator, 'membership.created', [
            'type' => 'membership.created',
            'membershipId' => $membership->id,
            'planId' => $plan->id,
            'memberId' => $request->user()->id,
            'status' => $membership->status,
        ]);

        $walletLedgerService->recordMembershipCredit($membership);

        return response()->json([
            'message' => __('messages.memberships.created'),
            'data' => [
                'membership' => new MembershipResource($membership),
            ],
        ], 201);
    }

    public function cancel(Request $request, Membership $membership): JsonResponse
    {
        $isMember = $membership->member_id === $request->user()->id;
        $isCreator = $membership->creator_id === $request->user()->id;
        abort_unless($isMember || $isCreator, 403);

        $membership->forceFill([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'ends_at' => now(),
        ])->save();

        $membership->load([
            'plan',
            'creator' => fn ($query) => $query->withProfileAggregates($request->user()),
            'member' => fn ($query) => $query->withProfileAggregates($request->user()),
        ]);

        $actorId = $request->user()->id;
        $recipientId = $isMember ? $membership->creator_id : $membership->member_id;
        UserNotifier::sendTranslated(
            $recipientId,
            $actorId,
            'membership_cancelled',
            'messages.notifications.membership_cancelled_title',
            'messages.notifications.membership_cancelled_body',
            ['name' => $request->user()->name, 'plan' => $membership->plan?->name ?? __('messages.memberships.membership')],
            ['membershipId' => $membership->id],
        );

        DeveloperWebhookDispatcher::dispatch($membership->creator, 'membership.cancelled', [
            'type' => 'membership.cancelled',
            'membershipId' => $membership->id,
            'planId' => $membership->creator_plan_id,
            'memberId' => $membership->member_id,
            'status' => $membership->status,
        ]);

        return response()->json([
            'message' => __('messages.memberships.cancelled'),
            'data' => [
                'membership' => new MembershipResource($membership),
            ],
        ]);
    }

    private function validatePlan(Request $request, bool $sometimes = false): array
    {
        $rules = [
            'name' => [$sometimes ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price_amount' => [$sometimes ? 'sometimes' : 'required', 'integer', 'min:0'],
            'currency' => [$sometimes ? 'sometimes' : 'required', 'string', 'size:3'],
            'billing_period' => [$sometimes ? 'sometimes' : 'required', 'string', Rule::in(self::BILLING_PERIODS)],
            'benefits' => ['nullable', 'array'],
            'benefits.*' => ['string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];

        return $request->validate($rules);
    }
}