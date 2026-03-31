<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PortalSearchService;
use App\Support\PortalSearchUtils;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PortalApiController extends Controller
{
    public function __construct(
        private readonly PortalSearchService $portalSearch,
    ) {
    }

    public function getSubject(string $id): JsonResponse
    {
        return response()->json($this->portalSearch->getSubject($id));
    }

    public function search(Request $request): JsonResponse
    {
        return response()->json($this->portalSearch->search($request));
    }

    public function autocomplete(Request $request): JsonResponse
    {
        return response()->json($this->portalSearch->autocomplete($request));
    }

    public function autocompleteFilter(Request $request): JsonResponse
    {
        return response()->json($this->portalSearch->autocompleteFilter($request));
    }

    public function getMiniMapData(Request $request): JsonResponse
    {
        return response()->json($this->portalSearch->getMiniMapData($request));
    }

    public function getSearchAggregationData(Request $request): JsonResponse
    {
        return response()->json($this->portalSearch->getSearchAggregationData($request));
    }

    public function getPeriodRegions(): JsonResponse
    {
        return response()->json($this->portalSearch->getPeriodRegions());
    }

    public function getPeriodsForCountry(Request $request): JsonResponse
    {
        return response()->json($this->portalSearch->getPeriodsForCountry($request));
    }

    public function getTotalRecordsCount(): JsonResponse
    {
        return response()->json($this->portalSearch->getTotalRecordsCount());
    }

    public function getAllServicesAndPublishers(): JsonResponse
    {
        return response()->json($this->portalSearch->getServicesAndPublishers());
    }

    public function getAllNoFormats(Request $request): JsonResponse
    {
        return response()->json($this->portalSearch->getNoFormats($request));
    }

    public function getRecord(Request $request, string $id): JsonResponse
    {
        return response()->json($this->portalSearch->getRecord($id, $request));
    }

    public function getRecordXml(Request $request, string $id): Response
    {
        $record = $this->portalSearch->getRecord($id, $request);
        $xml = PortalSearchUtils::recordAsXml($record ?? []);

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }
}
