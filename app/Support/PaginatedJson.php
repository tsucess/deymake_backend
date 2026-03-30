<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class PaginatedJson
{
    public static function empty(Request $request, int $defaultPerPage = 12, int $maxPerPage = 50): LengthAwarePaginator
    {
        return new \Illuminate\Pagination\LengthAwarePaginator(
            collect(),
            0,
            self::perPage($request, $defaultPerPage, $maxPerPage),
            max((int) $request->query('page', 1), 1),
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ],
        );
    }

    public static function items(Request $request, LengthAwarePaginator $paginator, string $resourceClass): array
    {
        return $resourceClass::collection($paginator->getCollection())->toArray($request);
    }

    public static function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
            'count' => $paginator->count(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'hasMorePages' => $paginator->hasMorePages(),
            'nextPageUrl' => $paginator->nextPageUrl(),
            'prevPageUrl' => $paginator->previousPageUrl(),
        ];
    }

    public static function paginate($query, Request $request, int $defaultPerPage = 12, int $maxPerPage = 50): LengthAwarePaginator
    {
        return $query
            ->paginate(self::perPage($request, $defaultPerPage, $maxPerPage))
            ->withQueryString();
    }

    public static function perPage(Request $request, int $defaultPerPage = 12, int $maxPerPage = 50): int
    {
        $rawPerPage = $request->query('per_page', $request->query('limit'));

        if (! is_numeric($rawPerPage)) {
            return $defaultPerPage;
        }

        return min(max((int) $rawPerPage, 1), $maxPerPage);
    }
}