<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AcademyCourseResource;
use App\Http\Resources\AcademyEnrollmentResource;
use App\Models\AcademyCourse;
use App\Models\AcademyEnrollment;
use App\Models\AcademyLesson;
use App\Models\AcademyLessonCompletion;
use App\Support\SupportedLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreatorAcademyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $courses = AcademyCourse::query()
            ->withCount(['lessons' => fn ($query) => $query->where('status', 'published')])
            ->where('status', 'published')
            ->orderBy('title')
            ->get();

        return response()->json([
            'message' => __('messages.academy.courses_retrieved'),
            'data' => [
                'courses' => AcademyCourseResource::collection($courses),
            ],
        ]);
    }

    public function show(Request $request, AcademyCourse $academyCourse): JsonResponse
    {
        SupportedLocales::apply($request);

        abort_if($academyCourse->status !== 'published', 404);
        $academyCourse->load([
            'lessons' => fn ($query) => $query->where('status', 'published')->orderBy('sort_order')->orderBy('id'),
        ]);

        return response()->json([
            'message' => __('messages.academy.course_retrieved'),
            'data' => [
                'course' => new AcademyCourseResource($academyCourse),
            ],
        ]);
    }

    public function enroll(Request $request, AcademyCourse $academyCourse): JsonResponse
    {
        SupportedLocales::apply($request);

        abort_if($academyCourse->status !== 'published', 404);
        $enrollment = AcademyEnrollment::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'academy_course_id' => $academyCourse->id],
            ['enrolled_at' => now()],
        );

        $enrollment->load([
            'course.lessons' => fn ($query) => $query->where('status', 'published')->orderBy('sort_order')->orderBy('id'),
        ])->loadCount('completions as completed_lessons_count');

        return response()->json([
            'message' => __('messages.academy.enrolled'),
            'data' => [
                'enrollment' => new AcademyEnrollmentResource($enrollment),
            ],
        ]);
    }

    public function completeLesson(Request $request, AcademyLesson $academyLesson): JsonResponse
    {
        SupportedLocales::apply($request);

        $academyLesson->load('course');
        abort_if($academyLesson->status !== 'published' || $academyLesson->course?->status !== 'published', 404);

        $enrollment = AcademyEnrollment::query()->firstOrCreate(
            ['user_id' => $request->user()->id, 'academy_course_id' => $academyLesson->academy_course_id],
            ['enrolled_at' => now()],
        );

        AcademyLessonCompletion::query()->updateOrCreate(
            ['academy_enrollment_id' => $enrollment->id, 'academy_lesson_id' => $academyLesson->id],
            ['completed_at' => now()],
        );

        $totalLessons = AcademyLesson::query()
            ->where('academy_course_id', $academyLesson->academy_course_id)
            ->where('status', 'published')
            ->count();

        $completedLessons = AcademyLessonCompletion::query()->where('academy_enrollment_id', $enrollment->id)->count();
        if ($totalLessons > 0 && $completedLessons >= $totalLessons) {
            $enrollment->forceFill(['completed_at' => now()])->save();
        }

        $enrollment->load([
            'course.lessons' => fn ($query) => $query->where('status', 'published')->orderBy('sort_order')->orderBy('id'),
        ])->loadCount('completions as completed_lessons_count');

        return response()->json([
            'message' => __('messages.academy.lesson_completed'),
            'data' => [
                'enrollment' => new AcademyEnrollmentResource($enrollment),
            ],
        ]);
    }

    public function myLearning(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $enrollments = AcademyEnrollment::query()
            ->where('user_id', $request->user()->id)
            ->with([
                'course.lessons' => fn ($query) => $query->where('status', 'published')->orderBy('sort_order')->orderBy('id'),
            ])
            ->withCount('completions as completed_lessons_count')
            ->latest('enrolled_at')
            ->get();

        return response()->json([
            'message' => __('messages.academy.learning_retrieved'),
            'data' => [
                'enrollments' => AcademyEnrollmentResource::collection($enrollments),
            ],
        ]);
    }
}