<?php

namespace App\Support;

class PortalTimeline
{
    public static function prepareRangeBucketsAggregation(?array $range): array
    {
        if (empty($range) || count($range) < 2) {
            $currentYear = (int) date('Y');
            $range = [-1000000, -100000, -10000, -1000, 0, 1000, 1250, 1500, 1750, $currentYear];
        } else {
            $range = array_map('intval', $range);
        }

        $intervalCount = count($range) - 1;
        $defaultBucketCount = 50;
        $bucketsPerInterval = $intervalCount > 1
            ? (int) floor($defaultBucketCount / $intervalCount)
            : $defaultBucketCount;

        $ranges = [];

        for ($intervalIndex = 0; $intervalIndex < $intervalCount; $intervalIndex++) {
            if ($intervalIndex === $intervalCount - 1) {
                $bucketsPerInterval += $defaultBucketCount % $intervalCount;
            }

            $startYear = $range[$intervalIndex];
            $endYear = $range[$intervalIndex + 1];
            $delta = ($endYear - $startYear) / $bucketsPerInterval;
            $currentStartYear = $startYear;

            for ($bucketIndex = 0; $bucketIndex < $bucketsPerInterval; $bucketIndex++) {
                $rangeStartYear = (int) round($currentStartYear);
                $rangeEndYear = (int) round($currentStartYear + $delta);

                $ranges[$rangeStartYear.':'.$rangeEndYear] = [
                    'bool' => [
                        'must' => [
                            ['range' => ['temporal.until' => ['gte' => (string) $rangeStartYear]]],
                            ['range' => ['temporal.from' => ['lte' => (string) $rangeEndYear]]],
                        ],
                    ],
                ];

                $currentStartYear += $delta;
            }
        }

        return [
            'nested' => ['path' => 'temporal'],
            'aggs' => [
                'range_agg' => [
                    'filters' => [
                        'filters' => $ranges,
                    ],
                ],
            ],
        ];
    }

    public static function buildRangeIntersectingInnerQuery(?string $range): ?array
    {
        if (!$range) {
            return null;
        }

        $rangeParts = explode(',', $range);

        if (count($rangeParts) < 2) {
            return null;
        }

        $start = (int) $rangeParts[0];
        $stop = (int) $rangeParts[1];

        $intersectingCombinations = [
            [
                ['range' => ['temporal.until' => ['gte' => $start]]],
                ['range' => ['temporal.until' => ['lte' => $stop]]],
            ],
            [
                ['range' => ['temporal.from' => ['gte' => $start]]],
                ['range' => ['temporal.until' => ['lte' => $stop]]],
            ],
            [
                ['range' => ['temporal.from' => ['gte' => $start]]],
                ['range' => ['temporal.from' => ['lte' => $stop]]],
            ],
            [
                ['range' => ['temporal.from' => ['lte' => $stop]]],
                ['range' => ['temporal.until' => ['gte' => $start]]],
            ],
        ];

        $queries = [];

        foreach ($intersectingCombinations as $combination) {
            $queries[] = [
                'nested' => [
                    'path' => 'temporal',
                    'query' => [
                        'bool' => [
                            'must' => $combination,
                        ],
                    ],
                ],
            ];
        }

        return $queries;
    }
}
