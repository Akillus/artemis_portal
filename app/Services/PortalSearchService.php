<?php

namespace App\Services;

use App\Support\PortalSearchUtils;
use App\Support\PortalTimeline;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use stdClass;

class PortalSearchService
{
    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $recordsIndex = null,
        private readonly ?string $subjectsIndex = null,
        private readonly ?string $periodsIndex = null,
        private readonly ?string $servicesIndex = null,
        private readonly ?string $publishersIndex = null,
        private readonly ?string $aatTermDescendantsIndex = null,
        private readonly ?string $defaultLanguage = null,
        private readonly ?int $mapMarkerThreshold = null,
    ) {
    }

    public function search(Request $request): array
    {
        if ($request->query('mapq')) {
            return $this->getSearchAggregationData($request);
        }

        return $this->resultToFrontend(
            $this->searchRecords($this->buildCurrentQuery($request->query()))
        );
    }

    public function autocomplete(Request $request): ?array
    {
        $query = strtolower(trim((string) $request->query('q', '')));

        if ($query === '') {
            return null;
        }

        $fields = trim((string) $request->query('fields', ''));
        $allFields = $fields === '' || $fields === 'all';

        if ($fields !== 'aatSubjects') {
            $escaped = sprintf('%1$s | %1$s*', PortalSearchUtils::escapeLuceneValue($query));
            $bool = ['should' => []];
            $fieldTypes = $allFields ? [
                'ariadneSubject.prefLabel.raw',
                'country.name.raw',
                'dataType.label.raw',
                'derivedSubject.prefLabel.raw',
                'description.text.raw',
                'nativeSubject.prefLabel.raw',
                'ariadneSubject.prefLabel^2',
                'country.name^2',
                'dataType.label^2',
                'derivedSubject.prefLabel^2',
                'description.text^2',
                'nativeSubject.prefLabel^2',
            ] : [];

            if ($allFields || $fields === 'title') {
                $fieldTypes[] = 'title.text.raw^3';
                $fieldTypes[] = 'title.text^4';
            }

            if (!empty($fieldTypes)) {
                $bool['should'][] = [
                    'simple_query_string' => [
                        'default_operator' => 'and',
                        'fields' => $fieldTypes,
                        'query' => $escaped,
                    ],
                ];
            }

            foreach ($this->autocompleteNestedFields($fields, $allFields) as $path => $nestedFields) {
                $bool['should'][] = [
                    'nested' => [
                        'path' => $path,
                        'query' => [
                            'simple_query_string' => [
                                'default_operator' => 'and',
                                'fields' => $nestedFields,
                                'query' => $escaped,
                            ],
                        ],
                    ],
                ];
            }

            $search = $this->searchRecords([
                '_source' => ['title'],
                'query' => ['bool' => $bool],
                'highlight' => ['fields' => ['*' => new stdClass()]],
            ]);

            $hits = [];

            foreach (Arr::get($search, 'hits.hits', []) as $hit) {
                $normalized = PortalSearchUtils::splitLanguages(Arr::get($hit, '_source', []), $this->defaultLanguage());
                $fieldHits = [];

                foreach (Arr::get($hit, 'highlight', []) as $highlightKey => $unused) {
                    $fieldHits[] = str_contains($highlightKey, '.') ? strstr($highlightKey, '.', true) : $highlightKey;
                }

                $hits[] = [
                    'id' => (string) $hit['_id'],
                    'label' => $normalized['title'] ?? null,
                    'fieldHits' => array_values(array_unique($fieldHits)),
                ];
            }

            return [
                'hasMoreResults' => (Arr::get($search, 'hits.total.value', 0) > count(Arr::get($search, 'hits.hits', []))),
                'hits' => $hits,
            ];
        }

        $search = $this->searchSubjects([
            '_source' => ['prefLabel', 'prefLabels'],
            'size' => 10,
            'query' => [
                'nested' => [
                    'path' => 'prefLabels',
                    'query' => [
                        'bool' => [
                            'must' => [
                                [
                                    'match_phrase_prefix' => [
                                        'prefLabels.label' => PortalSearchUtils::escapeLuceneValue($query).'*',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $hits = [];

        foreach (Arr::get($search, 'hits.hits', []) as $hit) {
            $label = Arr::get($hit, '_source.prefLabel');
            $variants = [];

            foreach (Arr::get($hit, '_source.prefLabels', []) as $variant) {
                if (($variant['label'] ?? null) !== $label) {
                    $variants[] = $variant;
                }
            }

            $hits[] = [
                'id' => (string) $hit['_id'],
                'label' => $label,
                'variants' => $variants,
            ];
        }

        return [
            'hasMoreResults' => (Arr::get($search, 'hits.total.value', 0) > count(Arr::get($search, 'hits.hits', []))),
            'hits' => $hits,
        ];
    }

    public function autocompleteFilter(Request $request): ?array
    {
        $query = PortalSearchUtils::escapeLuceneValue((string) $request->query('filterQuery', ''));
        $filterName = trim((string) $request->query('filterName', ''));

        if (($query === '' && $request->query('filterSize') === null) || $filterName === '') {
            return null;
        }

        $params = $request->query();
        unset($params[$filterName], $params['bbox']);

        $currentQuery = Arr::get($this->buildCurrentQuery($params), 'query', []);
        $size = (((int) $request->query('filterSize', 0)) * 20) + 20;

        return match (strtolower($filterName)) {
            'contributor' => $this->termsAutocompleteAggregation($currentQuery, 'contributor.name.raw', $size, $query),
            'country' => $this->termsAutocompleteAggregation($currentQuery, 'country.name.raw', $size, $query),
            'datatype' => $this->termsAutocompleteAggregation($currentQuery, 'dataType.label.raw', $size, $query),
            'nativesubject' => $this->termsAutocompleteAggregation($currentQuery, 'nativeSubject.prefLabel.raw', $size, $query, false),
            'ariadnesubject' => $this->termsAutocompleteAggregation($currentQuery, 'ariadneSubject.prefLabel.raw', $size, $query),
            'derivedsubject' => $this->termsAutocompleteAggregation($currentQuery, 'derivedSubject.prefLabel.raw', $size, $query, false),
            'publisher' => $this->termsAutocompleteAggregation($currentQuery, 'publisher.name.raw', $size, $query),
            'temporal' => $this->temporalAutocompleteAggregation($currentQuery, $size, $query),
            'temporalregion' => $this->temporalRegionAggregation($size, $query),
            'culturalperiods' => $this->culturalPeriodsAggregation($request, $size, $query),
            default => null,
        };
    }

    public function getMiniMapData(Request $request): array
    {
        $query = $this->buildCurrentQuery($request->query());
        unset($query['sort'], $query['from']);

        $query = $this->buildMapQuery($query, $request->query());
        $query['_source'] = ['title', 'spatial'];

        if (($query['size'] ?? 0) <= $this->mapMarkerThreshold()) {
            $query['aggregations']['geogridCentroid'] = $this->searchAggregations()['geogridCentroid'];
        } else {
            unset($query['aggregations']);
        }

        return $this->resultToFrontend($this->searchRecords($query));
    }

    public function getSearchAggregationData(Request $request): array
    {
        $params = $request->query();
        $query = $this->buildCurrentQuery($params);
        $query['aggregations'] = $this->searchAggregations();
        $query['size'] = 0;

        unset($query['_source'], $query['sort'], $query['from']);

        if ($request->query('timeline')) {
            $range = $request->query('range') ? explode(',', (string) $request->query('range')) : null;
            $query['aggregations'] = ['range_buckets' => PortalTimeline::prepareRangeBucketsAggregation($range)];
        } elseif ($request->query('mapq') && !$request->query('mapqAggs')) {
            $query = $this->buildMapQuery($query, $params);
        } else {
            unset($query['aggregations']['geogridCentroid']);

            if ((string) $request->query('operator', '') === '') {
                $pinnedAggregation = $this->buildPinnedAggregation($params, $query['aggregations']);

                if (!empty($pinnedAggregation)) {
                    $query['aggregations']['pinned'] = $pinnedAggregation;
                }
            }
        }

        $result = $this->searchRecords($query);

        if (!empty(Arr::get($result, 'aggregations.pinned.buckets'))) {
            $pinned = [];

            foreach ($result['aggregations']['pinned']['buckets'] as $key => $bucket) {
                $parts = explode('_', $key);
                $pinned[] = [
                    'key' => $parts[2] ?? '',
                    'type' => $parts[1] ?? '',
                    'doc_count' => $bucket['doc_count'] ?? 0,
                ];
            }

            $result['aggregations']['pinned']['buckets'] = $pinned;
        }

        $temporalBuckets = Arr::get($result, 'aggregations.temporal.temporal.buckets');
        $temporalBucketsNested = Arr::get($result, 'aggregations.temporal.temporal.temporal.buckets');

        if (is_array($temporalBuckets)) {
            $this->mergeRootCount($temporalBuckets);
            $result['aggregations']['temporal']['temporal']['buckets'] = $temporalBuckets;
        } elseif (is_array($temporalBucketsNested)) {
            $this->mergeRootCount($temporalBucketsNested);
            $result['aggregations']['temporal']['temporal']['temporal']['buckets'] = $temporalBucketsNested;
        }

        return $this->resultToFrontend($result);
    }

    public function getPeriodRegions(): array
    {
        return $this->resultToFrontend($this->searchPeriods([
            'size' => 0,
            'aggregations' => [
                'periodCountry' => [
                    'terms' => [
                        'field' => 'spatialCoverage.label.raw',
                        'order' => ['_count' => 'desc'],
                        'size' => 20,
                    ],
                ],
            ],
        ]));
    }

    public function getPeriodsForCountry(Request $request): array
    {
        $temporalRegion = trim((string) $request->query('temporalRegion', ''));

        $query = [
            '_source' => ['authority', 'label', 'languageTag', 'spatialCoverage', 'localizedLabels', 'start', 'stop', 'total', 'timestamp'],
            'size' => 20,
            'sort' => ['start.year' => ['order' => 'asc']],
        ];

        if ($temporalRegion === '') {
            $query['query']['bool']['must'] = ['match_all' => new stdClass()];
        } else {
            $parts = [];

            foreach (explode('|', $temporalRegion) as $region) {
                $parts[] = ['match' => ['spatialCoverage.label.raw' => PortalSearchUtils::escapeLuceneValue($region)]];
            }

            $query['query'] = [
                'bool' => [
                    'must' => [
                        ['bool' => ['should' => $parts]],
                    ],
                ],
            ];
        }

        return $this->periodsToAggs($this->searchPeriods($query), $request);
    }

    public function getTotalRecordsCount(): int
    {
        return (int) Arr::get($this->get('/'.$this->recordsIndex().'/_count'), 'count', 0);
    }

    public function getServicesAndPublishers(): array
    {
        return [
            'services' => $this->simpleIndexListing($this->servicesIndex()),
            'publishers' => $this->simpleIndexListing($this->publishersIndex()),
        ];
    }

    public function getNoFormats(Request $request): array
    {
        $publishers = (string) $request->query('publishers', '');

        if ($publishers === '') {
            return [];
        }

        $parts = [];

        foreach (explode('|', $publishers) as $publisher) {
            $parts[] = ['match' => ['publisher.name.raw' => PortalSearchUtils::escapeLuceneValue($publisher)]];
        }

        $query = [
            'size' => 0,
            'query' => [
                'bool' => [
                    'must' => [
                        ['bool' => ['should' => $parts]],
                    ],
                ],
            ],
            'aggregations' => [
                'subject' => [
                    'terms' => [
                        'size' => 10000,
                        'field' => 'nativeSubject.prefLabel.raw',
                    ],
                ],
                'temporal' => [
                    'nested' => ['path' => 'temporal'],
                    'aggs' => [
                        'temporal' => [
                            'terms' => [
                                'size' => 10000,
                                'field' => 'temporal.periodName.raw',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->searchRecords($query);
        $values = [];

        foreach (array_keys($query['aggregations']) as $key) {
            $buckets = Arr::get($result, "aggregations.{$key}.{$key}.buckets", Arr::get($result, "aggregations.{$key}.buckets", []));

            foreach ($buckets as $bucket) {
                $values[] = $bucket['key'];
            }
        }

        return $values;
    }

    public function getRecord(string $id, Request $request): ?array
    {
        $record = $this->get('/'.$this->recordsIndex()."/_doc/{$id}", true);

        if (!Arr::get($record, 'found')) {
            return null;
        }

        $source = Arr::get($record, '_source', []);
        $source['id'] = $id;
        $source['similar'] = $this->getThematicallySimilarItems($source, $id, (string) $request->query('thematical', ''));
        $source['nearby'] = $this->getNearbySpatialResources($source);
        $source['collection'] = $this->getCollectionItems($source);
        $source['partOf'] = $this->getItemsPartOf($source);
        $source['isAboutResource'] = $this->getIsAboutResources($source);
        $source['periodo'] = $this->getPeriodsForRecord($source);

        return PortalSearchUtils::splitLanguages($source, $this->defaultLanguage());
    }

    public function getSubject(string $id): ?array
    {
        if (!is_numeric($id)) {
            $title = PortalSearchUtils::escapeLuceneValue(urldecode($id));
            $records = $this->searchRecords([
                '_source' => ['derivedSubject'],
                'size' => 1,
                'query' => [
                    'bool' => [
                        'must' => [
                            ['match' => ['derivedSubject.prefLabel.raw' => $title]],
                        ],
                    ],
                ],
            ]);

            foreach (Arr::get($records, 'hits.hits.0._source.derivedSubject', []) as $subject) {
                if (($subject['prefLabel'] ?? null) === $title) {
                    $parts = explode('/', (string) $subject['id']);
                    $id = (string) end($parts);
                    break;
                }
            }
        }

        if ($id === '') {
            return null;
        }

        $subject = $this->get('/'.$this->subjectsIndex()."/_doc/{$id}", true);

        if (!Arr::get($subject, 'found')) {
            return null;
        }

        $data = Arr::get($subject, '_source', []);
        $data['id'] = $id;
        $data['subSubjects'] = $this->getSubSubjects($id);

        return $data;
    }

    private function autocompleteNestedFields(string $fields, bool $allFields): array
    {
        $nested = [];

        if ($allFields || $fields === 'location') {
            $nested['spatial'] = ['spatial.placeName.raw', 'spatial.placeName^2'];
        }

        if ($allFields || $fields === 'time') {
            $nested['temporal'] = ['temporal.periodName.raw', 'temporal.periodName^2'];
        }

        return $nested;
    }

    private function buildCurrentQuery(array $params): array
    {
        $query = [
            'size' => $this->getSize($params),
            'from' => $this->getFrom($params),
            'sort' => $this->getSort($params),
        ];

        $operator = (($params['operator'] ?? '') === 'or') ? 'or' : 'and';
        $q = trim((string) ($params['q'] ?? ''));

        if ($q === '') {
            $innerQuery = ['bool' => ['should' => ['match_all' => new stdClass()]]];
        } else {
            $raw = PortalSearchUtils::escapeLuceneValue($q, false);
            $search = sprintf('%s | "%s"', $raw, $raw);
            $searchField = trim((string) ($params['fields'] ?? ''));
            $fields = $this->validSearchableFields();

            if ($searchField !== '' && !empty($fields[$searchField])) {
                $fieldQuery = [
                    'simple_query_string' => [
                        'default_operator' => $operator,
                        'fields' => [explode('^', $fields[$searchField]['fieldPath'])[0]],
                        'query' => $searchField === 'title' ? sprintf('%s | %s*', $search, $raw) : $search,
                    ],
                ];

                if (!empty($fields[$searchField]['nested'])) {
                    $innerQuery['bool'] = [
                        'minimum_should_match' => 1,
                        'should' => [
                            'nested' => [
                                'path' => $fields[$searchField]['nested'],
                                'query' => $fieldQuery,
                            ],
                        ],
                    ];
                } else {
                    $innerQuery['bool'] = [
                        'minimum_should_match' => 1,
                        'should' => $fieldQuery,
                    ];
                }
            } else {
                $searchFields = [];
                $nestedFields = [];

                foreach ($fields as $field) {
                    if (empty($field['nested'])) {
                        $searchFields[] = $field['fieldPath'];
                    } else {
                        $nestedFields[] = [
                            'nested' => [
                                'path' => $field['nested'],
                                'query' => [
                                    'simple_query_string' => [
                                        'default_operator' => $operator,
                                        'fields' => [$field['fieldPath']],
                                        'query' => $search,
                                    ],
                                ],
                            ],
                        ];
                    }
                }

                $innerQuery['bool'] = [
                    'minimum_should_match' => 1,
                    'should' => array_merge([
                        [
                            'simple_query_string' => [
                                'default_operator' => $operator,
                                'fields' => $searchFields,
                                'query' => $search,
                            ],
                        ],
                        [
                            'simple_query_string' => [
                                'default_operator' => $operator,
                                'query' => $search,
                            ],
                        ],
                        [
                            'simple_query_string' => [
                                'default_operator' => $operator,
                                'fields' => ['title.text'],
                                'query' => $raw.'*',
                            ],
                        ],
                    ], $nestedFields),
                ];
            }
        }

        $query['query'] = $innerQuery;
        $filters = $this->getFilters($params);

        if (!empty($filters)) {
            $musts = [];

            foreach ($filters as $filter) {
                switch ($params['operator'] ?? '') {
                    case 'or':
                        $query['query']['bool']['filter']['bool']['should'][] = $filter;
                        break;
                    case 'and':
                        $query['query']['bool']['filter'][] = $filter;
                        break;
                    default:
                        $term = $filter['term'] ?? $filter['terms'] ?? $filter['nested']['query']['bool']['must'][0]['term'] ?? null;
                        if (!empty($term)) {
                            $musts[array_key_first($term)][] = $filter;
                        } else {
                            $query['query']['bool']['filter']['bool']['must'][] = $filter;
                        }
                }
            }

            foreach ($musts as $value) {
                $query['query']['bool']['filter']['bool']['must'][] = ['bool' => ['should' => $value]];
            }
        }

        return $query;
    }

    private function getFilters(array $params): array
    {
        $filters = [];

        foreach ($params as $param => $paramValue) {
            $values = explode('|', (string) $paramValue);

            if ($param === 'culturalPeriods') {
                $filters[] = [
                    'bool' => [
                        'should' => [
                            'nested' => [
                                'path' => 'temporal',
                                'query' => [
                                    'bool' => [
                                        'should' => [
                                            'terms' => ['temporal.uri' => $values],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ];

                continue;
            }

            foreach ($values as $value) {
                $filter = $this->getValidFilter($param, $value, $params);

                if (!$filter) {
                    continue;
                }

                $filterValue = $filter['innerQuery'] ?? [];

                if (!empty($filter['isNested'])) {
                    $filterValue = [
                        'nested' => [
                            'path' => $filter['fieldPath'],
                            'query' => [
                                'bool' => [
                                    'must' => !empty($filter['isArrayNested']) ? $filter['innerQuery'] : [$filter['innerQuery']],
                                ],
                            ],
                        ],
                    ];
                }

                if (($filter['operator'] ?? null) === 'OR') {
                    $filters[] = ['bool' => ['should' => $filterValue]];
                } else {
                    $filters[] = $filterValue;
                }
            }
        }

        return $filters;
    }

    private function getValidFilter(string $filterName, string $filterValue, array $params): ?array
    {
        $validFilters = [
            'bbox' => [
                'fieldPath' => 'temporal',
                'isNested' => false,
                'operator' => 'OR',
                'innerQuery' => $this->boundingBoxFilter($params),
            ],
            'ariadneSubject' => [
                'fieldPath' => 'ariadneSubject',
                'isNested' => false,
                'innerQuery' => ['term' => ['ariadneSubject.prefLabel.raw' => $filterValue]],
            ],
            'derivedSubject' => [
                'fieldPath' => 'derivedSubject',
                'isNested' => false,
                'innerQuery' => $this->derivedSubjectFilter($filterValue),
            ],
            'contributor' => [
                'fieldPath' => 'contributor',
                'isNested' => false,
                'innerQuery' => ['term' => ['contributor.name.raw' => $filterValue]],
            ],
            'country' => [
                'fieldPath' => 'country',
                'isNested' => false,
                'innerQuery' => ['term' => ['country.name.raw' => $filterValue]],
            ],
            'dataType' => [
                'fieldPath' => 'dataType',
                'isNested' => false,
                'innerQuery' => ['term' => ['dataType.label.raw' => $filterValue]],
            ],
            'publisher' => [
                'fieldPath' => 'publisher',
                'isNested' => false,
                'innerQuery' => ['term' => ['publisher.name.raw' => $filterValue]],
            ],
            'temporal' => [
                'fieldPath' => 'temporal',
                'isNested' => true,
                'innerQuery' => ['term' => ['temporal.periodName.raw' => $filterValue]],
            ],
            'nativeSubject' => [
                'fieldPath' => 'nativeSubject',
                'isNested' => false,
                'innerQuery' => ['term' => ['nativeSubject.prefLabel.raw' => $filterValue]],
            ],
            'creator' => [
                'fieldPath' => 'creator',
                'isNested' => false,
                'innerQuery' => ['term' => ['creator.name.raw' => $filterValue]],
            ],
            'owner' => [
                'fieldPath' => 'owner',
                'isNested' => false,
                'innerQuery' => ['term' => ['owner.name.raw' => $filterValue]],
            ],
            'responsible' => [
                'fieldPath' => 'responsible',
                'isNested' => false,
                'innerQuery' => ['term' => ['responsible.name.raw' => $filterValue]],
            ],
            'resourceType' => [
                'fieldPath' => 'resourceType',
                'isNested' => false,
                'innerQuery' => ['term' => ['resourceType' => $filterValue]],
            ],
            'placeName' => [
                'fieldPath' => 'spatial',
                'isNested' => true,
                'innerQuery' => ['term' => ['spatial.placeName.raw' => $filterValue]],
            ],
            'isPartOf' => [
                'fieldPath' => 'isPartOf',
                'isNested' => false,
                'innerQuery' => ['match_phrase' => ['isPartOf' => $filterValue]],
            ],
            'range' => [
                'fieldPath' => 'temporal',
                'isNested' => false,
                'operator' => 'OR',
                'isArrayNested' => true,
                'innerQuery' => PortalTimeline::buildRangeIntersectingInnerQuery($filterValue),
            ],
        ];

        return $validFilters[$filterName] ?? null;
    }

    private function buildMapQuery(array $mainQuery, array $params): array
    {
        $mainQuery['_source'] = ['title', 'description', 'resourceType', 'publisher', 'ariadneSubject', 'spatial'];
        $mainQuery['aggregations']['viewport'] = [
            'nested' => ['path' => 'spatial'],
            'aggs' => [
                'thisBounds' => [
                    'geo_bounds' => [
                        'field' => 'spatial.geopoint',
                        'wrap_longitude' => true,
                    ],
                ],
            ],
        ];

        $filterType = in_array((string) ($params['operator'] ?? ''), ['', 'or'], true) ? 'must' : 'filter';

        $mainQuery['query']['bool'][$filterType][] = [
            'nested' => [
                'path' => 'spatial',
                'query' => [
                    'bool' => [
                        'should' => [
                            ['exists' => ['field' => 'spatial.geopoint']],
                            ['exists' => ['field' => 'spatial.polygon']],
                            ['exists' => ['field' => 'spatial.boundingbox']],
                            ['exists' => ['field' => 'spatial.centroid']],
                        ],
                    ],
                ],
            ],
        ];

        $countResult = $this->post('/'.$this->recordsIndex().'/_count', ['query' => $mainQuery['query']]);
        $count = (int) ($countResult['count'] ?? 0);

        if ($count <= $this->mapMarkerThreshold()) {
            $mainQuery['size'] = $this->mapMarkerThreshold();
            $center = [25.3167, 54.9];

            if (!empty($params['bbox'])) {
                $bbox = explode(',', (string) $params['bbox']);
                $aLat = (float) ($bbox[0] ?? 0);
                $aLon = (float) ($bbox[1] ?? 0);
                $bLat = (float) ($bbox[2] ?? 0);
                $bLon = (float) ($bbox[3] ?? 0);
                $center = [($aLon + $bLon) / 2, ($aLat + $bLat) / 2];
            }

            $mainQuery['sort'] = [
                '_geo_distance' => [
                    'spatial.geopoint' => $center,
                    'order' => 'asc',
                    'mode' => 'min',
                    'nested' => ['path' => 'spatial'],
                ],
            ];
        } else {
            $mainQuery['size'] = 0;
        }

        return $mainQuery;
    }

    private function buildPinnedAggregation(array $params, array $aggregations): array
    {
        $filters = $this->getFilters($params);
        $musts = [];

        foreach ($filters as $filter) {
            $term = $filter['term'] ?? $filter['terms'] ?? $filter['nested']['query']['bool']['must'][0]['term'] ?? null;

            if (!empty($term)) {
                $musts[array_key_first($term)][] = $filter;
            } elseif (isset($params['range']) && !empty($filter['bool']['should'][0]['nested']['query']['bool']['must'][0]['range'] ?? null)) {
                $musts['range'][] = $filter;
            } elseif (isset($params['bbox']) && !empty($filter['bool']['should'][0]['nested']['query']['geo_bounding_box'] ?? null)) {
                $musts['bbox'][] = $filter;
            } elseif (isset($params['culturalPeriods']) && !empty($filter['bool']['should']['nested']['query']['bool']['should']['terms']['temporal.uri'] ?? null)) {
                $musts['periods'][] = $filter;
            }
        }

        if (empty($musts)) {
            return [];
        }

        $pinned = ['filters' => ['filters' => []]];

        foreach ($musts as $pinKey => $pinAggs) {
            $key = explode('.', $pinKey)[0];

            if (empty($aggregations[$key])) {
                continue;
            }

            $count = 0;
            $nested = $key === 'temporal';

            foreach ($pinAggs as $pinAgg) {
                $pinValue = $nested
                    ? (explode('|', (string) ($params[$key] ?? ''))[$count] ?? '')
                    : (($pinAgg['term'] ?? $pinAgg['terms'])[$pinKey] ?? '');

                if ($pinValue === '' || $pinValue === []) {
                    $count++;
                    continue;
                }

                $pinId = $nested ? $key.'.periodName.raw' : $pinKey;
                $filterId = 'pin_'.$key.'_'.$pinValue;

                $pinned['filters']['filters'][$filterId] = [
                    'bool' => [
                        'must' => [[
                            'bool' => [
                                'should' => $nested
                                    ? [[
                                        'nested' => [
                                            'path' => $key,
                                            'query' => [
                                                'bool' => [
                                                    'must' => [
                                                        ['term' => [$pinId => $pinValue]],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ]]
                                    : [['term' => [$pinId => $pinValue]]],
                            ],
                        ]],
                    ],
                ];

                $count++;
            }
        }

        return empty($pinned['filters']['filters']) ? [] : $pinned;
    }

    private function searchAggregations(): array
    {
        return [
            'ariadneSubject' => ['terms' => ['field' => 'ariadneSubject.prefLabel.raw', 'size' => 20]],
            'derivedSubject' => ['terms' => ['field' => 'derivedSubject.prefLabel.raw', 'size' => 20]],
            'contributor' => ['terms' => ['field' => 'contributor.name.raw', 'size' => 20]],
            'country' => ['terms' => ['field' => 'country.name.raw', 'size' => 20]],
            'dataType' => ['terms' => ['field' => 'dataType.label.raw', 'size' => 20]],
            'publisher' => ['terms' => ['field' => 'publisher.name.raw', 'size' => 20]],
            'temporal' => [
                'nested' => ['path' => 'temporal'],
                'aggs' => [
                    'temporal' => [
                        'terms' => ['field' => 'temporal.periodName.raw', 'size' => 20],
                        'aggs' => ['root_count' => ['reverse_nested' => new stdClass()]],
                    ],
                ],
            ],
            'nativeSubject' => ['terms' => ['field' => 'nativeSubject.prefLabel.raw', 'size' => 20]],
            'geogridCentroid' => [
                'nested' => ['path' => 'spatial'],
                'aggs' => [
                    'grids' => [
                        'geohash_grid' => [
                            'field' => 'spatial.centroid',
                            'precision' => 7,
                            'size' => 5000,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function searchSorts(): array
    {
        return [
            '_score' => ['key' => '_score', 'nested' => null],
            'issued' => ['key' => 'issued', 'nested' => null],
            'datingfrom' => ['key' => 'temporal.from', 'nested' => 'temporal'],
            'datingto' => ['key' => 'temporal.until', 'nested' => 'temporal'],
            'publisher' => ['key' => 'publisher.name.raw', 'nested' => null],
            'resource' => ['key' => 'ariadneSubject.prefLabel.raw', 'nested' => null],
        ];
    }

    private function validSearchableFields(): array
    {
        return [
            'title' => ['fieldPath' => 'title.text^40', 'nested' => null],
            'description' => ['fieldPath' => 'description.text^10', 'nested' => null],
            'nativeSubject' => ['fieldPath' => 'nativeSubject.prefLabel^5', 'nested' => null],
            'derivedSubject' => ['fieldPath' => 'derivedSubject.prefLabel^5', 'nested' => null],
            'location' => ['fieldPath' => 'spatial.placeName^2', 'nested' => 'spatial'],
            'time' => ['fieldPath' => 'temporal.periodName^2', 'nested' => 'temporal'],
        ];
    }

    private function boundingBoxFilter(array $params): array
    {
        if (empty($params['bbox'])) {
            return [];
        }

        $bbox = explode(',', (string) $params['bbox']);
        $filters = [];

        $filters[] = [
            'nested' => [
                'path' => 'spatial',
                'query' => [
                    'geo_bounding_box' => [
                        'spatial.geopoint' => [
                            'top_left' => ['lat' => (float) ($bbox[0] ?? 0), 'lon' => (float) ($bbox[1] ?? 0)],
                            'bottom_right' => ['lat' => (float) ($bbox[2] ?? 0), 'lon' => (float) ($bbox[3] ?? 0)],
                        ],
                    ],
                ],
            ],
        ];

        foreach (['polygon', 'boundingbox'] as $geoShape) {
            $filters[] = [
                'nested' => [
                    'path' => 'spatial',
                    'query' => [
                        'geo_shape' => [
                            'spatial.'.$geoShape => [
                                'shape' => [
                                    'type' => 'envelope',
                                    'relation' => 'within',
                                    'coordinates' => [
                                        [(float) ($bbox[1] ?? 0), (float) ($bbox[0] ?? 0)],
                                        [(float) ($bbox[3] ?? 0), (float) ($bbox[2] ?? 0)],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        return $filters;
    }

    private function derivedSubjectFilter(string $term): array
    {
        $resource = $this->searchRecords([
            'size' => 1,
            'query' => ['term' => ['derivedSubject.prefLabel.raw' => $term]],
        ]);

        $subjects = Arr::get($resource, 'hits.hits.0._source.derivedSubject', []);
        $aatId = null;

        foreach ($subjects as $subject) {
            if (($subject['prefLabel'] ?? null) === $term) {
                $parts = explode('/', (string) ($subject['id'] ?? ''));
                $aatId = end($parts);
                break;
            }
        }

        if (!$aatId) {
            return ['term' => ['derivedSubject.prefLabel.raw' => $term]];
        }

        $descendantsResult = $this->request('POST', '/'.$this->aatTermDescendantsIndex().'/_search', [
            'size' => 1,
            'query' => ['term' => ['id' => $aatId]],
        ]);

        $document = Arr::get($descendantsResult, 'hits.hits.0._source');

        if (!$document) {
            return ['term' => ['derivedSubject.prefLabel.raw' => $term]];
        }

        $descendants = [$aatId];

        foreach (($document['descendants'] ?? []) as $descendant) {
            $parts = explode('/', (string) ($descendant['uri'] ?? ''));
            $id = end($parts);

            if ($id) {
                $descendants[] = $id;
            }
        }

        return ['terms' => ['derivedSubject.id' => array_values(array_unique($descendants))]];
    }

    private function termsAutocompleteAggregation(array $currentQuery, string $field, int $size, string $query, bool $caseAware = true): array
    {
        $aggregation = [
            'size' => 0,
            'query' => $currentQuery,
            'aggregations' => [
                'filtered_agg' => [
                    'terms' => [
                        'field' => $field,
                        'size' => $size,
                        'order' => ['_count' => 'desc'],
                    ],
                ],
                'unique_agg_count' => ['cardinality' => ['field' => $field]],
            ],
        ];

        if ($query !== '') {
            $aggregation['aggregations']['filtered_agg']['terms']['include'] = $caseAware
                ? $this->includeRegexp($query)
                : '(.*'.strtolower($query).'.*)';
        }

        return Arr::get($this->searchRecords($aggregation), 'aggregations', []);
    }

    private function temporalAutocompleteAggregation(array $currentQuery, int $size, string $query): array
    {
        $aggregation = [
            'size' => 0,
            'query' => $currentQuery,
            'aggregations' => [
                'temporal_agg' => [
                    'nested' => ['path' => 'temporal'],
                    'aggs' => [
                        'filtered_agg' => [
                            'terms' => [
                                'field' => 'temporal.periodName.raw',
                                'size' => $size,
                                'order' => ['_count' => 'desc'],
                            ],
                        ],
                        'unique_agg_count' => ['cardinality' => ['field' => 'temporal.periodName.raw']],
                    ],
                ],
            ],
        ];

        if ($query !== '') {
            $aggregation['aggregations']['temporal_agg']['aggs']['filtered_agg']['terms']['include'] = '(.*'.strtolower($query).'.*)';
        }

        return Arr::get($this->searchRecords($aggregation), 'aggregations.temporal_agg', []);
    }

    private function temporalRegionAggregation(int $size, string $query): array
    {
        $aggregation = [
            'size' => 0,
            'aggregations' => [
                'filtered_agg' => [
                    'terms' => [
                        'field' => 'spatialCoverage.label.raw',
                        'size' => $size,
                        'order' => ['_count' => 'desc'],
                    ],
                ],
            ],
        ];

        if ($query !== '') {
            $aggregation['aggregations']['filtered_agg']['terms']['include'] = '('.strtolower($query).'.*)';
        }

        return Arr::get($this->searchPeriods($aggregation), 'aggregations', []);
    }

    private function culturalPeriodsAggregation(Request $request, int $size, string $query): array
    {
        $temporalRegion = trim((string) $request->query('temporalRegion', ''));
        $filterRegionQuery = null;

        foreach (explode('|', $temporalRegion) as $region) {
            if ($region === '') {
                $filterRegionQuery['bool']['should'] = ['match_all' => new stdClass()];
                break;
            }

            $filterRegionQuery['bool']['should'][] = ['term' => ['spatialCoverage.label.raw' => PortalSearchUtils::escapeLuceneValue($region)]];
        }

        $queryBody = [
            'size' => $size,
            'sort' => ['start.year' => ['order' => 'asc']],
            'query' => [
                'bool' => [
                    'must' => [
                        'nested' => [
                            'path' => 'localizedLabels',
                            'query' => [
                                'bool' => [
                                    'must' => [
                                        ['wildcard' => ['localizedLabels.label.raw' => $query.'*']],
                                        ['match' => ['localizedLabels.language' => 'en']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        if ($filterRegionQuery) {
            $queryBody['query']['bool']['filter'] = $filterRegionQuery;
        }

        return $this->periodsToAggs($this->searchPeriods($queryBody), $request);
    }

    private function periodsToAggs(array $periodsResult, Request $request): array
    {
        $buckets = [];

        foreach (Arr::get($periodsResult, 'hits.hits', []) as $period) {
            $source = $period['_source'] ?? [];
            $bucket = [
                'key' => $period['_id'],
                'region' => $source['spatialCoverage'][0]['label'] ?? '',
                'start' => (int) ($source['start']['year'] ?? 0),
                'filterLabel' => $source['label'] ?? '',
                'doc_count' => $source['total'] ?? 0,
            ];

            if (!empty($source['localizedLabels']) && (($source['languageTag'] ?? '') !== 'en' || !$bucket['filterLabel'])) {
                foreach (array_reverse($source['localizedLabels']) as $label) {
                    if (($label['language'] ?? null) === 'en') {
                        $bucket['filterLabel'] = $label['label'];
                        if ($bucket['filterLabel'] === ($source['label'] ?? null)) {
                            break;
                        }
                    }
                }
            }

            $bucket['filterLabel'] = $bucket['filterLabel'] ?: 'Unknown';

            if (!empty($source['timestamp']) && (int) $source['timestamp'] < time()) {
                $bucket['hasUpdate'] = true;
            }

            $bucket['timespan'] = ($source['start']['year'] ?? 0).', '.($source['stop']['year'] ?? 0);
            $bucket['extraLabels']['start'] = ($source['start']['label'] ?? '').' (Year: '.($source['start']['year'] ?? 'N/A').') ';
            $bucket['extraLabels']['stop'] = ($source['stop']['label'] ?? '').' (Year: '.($source['stop']['year'] ?? 'N/A').')';
            $bucket['extraLabels']['nativePeriodName'] = $source['label'] ?? '';
            $bucket['extraLabels']['authority'] = $source['authority'] ?? '';

            if (!empty($source['localizedLabels'])) {
                $bucket['extraLabels']['localizedLabels'] = collect($source['localizedLabels'])
                    ->map(fn (array $label): string => ($label['label'] ?? '').' ('.($label['language'] ?? '').')')
                    ->implode(', ');
            }

            if (!empty($source['spatialCoverage'])) {
                $bucket['extraLabels']['region'] = collect($source['spatialCoverage'])
                    ->map(fn (array $spatial): string => (string) ($spatial['label'] ?? ''))
                    ->implode(', ');
            }

            $buckets[] = $bucket;
        }

        return [
            'filtered_agg' => [
                'buckets' => $buckets,
                'sum_other_doc_count' => ($size = ((((int) $request->query('filterSize', 0)) * 20) + 20)) < Arr::get($periodsResult, 'hits.total.value', 0)
                    ? Arr::get($periodsResult, 'hits.total.value', 0)
                    : 0,
            ],
        ];
    }

    private function mergeRootCount(array &$buckets): void
    {
        foreach ($buckets as $key => $bucket) {
            if (isset($bucket['root_count']['doc_count'])) {
                $buckets[$key]['doc_count'] = $bucket['root_count']['doc_count'];
                unset($buckets[$key]['root_count']);
            }
        }
    }

    private function resultToFrontend(array $result): array
    {
        $hits = [];

        foreach (Arr::get($result, 'hits.hits', []) as $hit) {
            $hits[] = [
                'id' => $hit['_id'],
                'data' => PortalSearchUtils::splitLanguages(Arr::get($hit, '_source', []), $this->defaultLanguage()),
            ];
        }

        return [
            'total' => $result['hits']['total'] ?? 0,
            'hits' => $hits,
            'aggregations' => $result['aggregations'] ?? [],
        ];
    }

    private function getSort(array $params): array
    {
        $sortKey = $params['sort'] ?? null;

        if ($sortKey && isset($this->searchSorts()[$sortKey])) {
            $sort = $this->searchSorts()[$sortKey];
            $result = [
                $sort['key'] => [
                    'order' => (($params['order'] ?? '') === 'desc') ? 'desc' : 'asc',
                ],
            ];

            if (!empty($sort['nested'])) {
                $result[$sort['key']]['nested']['path'] = $sort['nested'];
            }

            return $result;
        }

        return ['_score' => ['order' => 'desc']];
    }

    private function getFrom(array $params): int
    {
        $from = (int) ($params['page'] ?? 0);

        if ($from < 2) {
            return 0;
        }

        return ($from - 1) * $this->getSize($params);
    }

    private function getSize(array $params): int
    {
        return min(max((int) ($params['size'] ?? 20), 0), 50);
    }

    private function getNearbySpatialResources(array $record): array
    {
        $point = $this->firstSpatialPoint($record);

        if (!$point) {
            return [];
        }

        $distance = 0.03;
        $query = [
            'size' => 50,
            '_source' => ['title', 'spatial', 'language'],
            'query' => [
                'bool' => [
                    'filter' => [[
                        'bool' => [
                            'should' => [
                                [
                                    'nested' => [
                                        'path' => 'spatial',
                                        'query' => [
                                            'geo_bounding_box' => [
                                                'spatial.geopoint' => [
                                                    'top_left' => ['lat' => $point['lat'] + $distance, 'lon' => $point['lon'] - $distance],
                                                    'bottom_right' => ['lat' => $point['lat'] - $distance, 'lon' => $point['lon'] + $distance],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                [
                                    'nested' => [
                                        'path' => 'spatial',
                                        'query' => [
                                            'geo_bounding_box' => [
                                                'spatial.centroid' => [
                                                    'top_left' => ['lat' => $point['lat'] + $distance, 'lon' => $point['lon'] - $distance],
                                                    'bottom_right' => ['lat' => $point['lat'] - $distance, 'lon' => $point['lon'] + $distance],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ]],
                    'must_not' => [
                        'term' => ['_id' => $record['id']],
                    ],
                ],
            ],
        ];

        $result = $this->searchRecords($query);
        $nearby = [];

        foreach (Arr::get($result, 'hits.hits', []) as $resource) {
            $nearby[] = [
                'title' => PortalSearchUtils::splitLanguages(Arr::get($resource, '_source', []), $this->defaultLanguage())['title'] ?? '',
                'spatial' => Arr::get($resource, '_source.spatial', []),
                'id' => $resource['_id'],
            ];
        }

        return $nearby;
    }

    private function getIsAboutResources(array $record): array
    {
        $parts = [];

        foreach (($record['is_about'] ?? []) as $item) {
            if (!empty($item['uri'])) {
                $parts[] = ['match_phrase' => ['is_about.uri' => $item['uri']]];
            }
        }

        if (empty($parts)) {
            return [];
        }

        $result = $this->searchRecords([
            '_source' => ['title'],
            'query' => [
                'bool' => [
                    'must_not' => [['term' => ['_id' => $record['id']]]],
                    'filter' => [['bool' => ['should' => $parts]]],
                ],
            ],
        ]);

        $resources = [];

        foreach (Arr::get($result, 'hits.hits', []) as $hit) {
            $normalized = PortalSearchUtils::splitLanguages(Arr::get($hit, '_source', []), $this->defaultLanguage());
            $resources[] = ['id' => $hit['_id'], 'title' => $normalized['title'] ?? []];
        }

        return $resources;
    }

    private function getPeriodsForRecord(array $record): ?array
    {
        $periods = [];

        foreach (($record['temporal'] ?? []) as $temporal) {
            $parts = explode('/', (string) ($temporal['uri'] ?? ''));
            if (in_array('n2t.net', $parts, true)) {
                $periods[] = ['match' => ['id' => PortalSearchUtils::escapeLuceneValue((string) end($parts))]];
            }
        }

        if (empty($periods)) {
            return null;
        }

        $result = $this->searchPeriods([
            'query' => [
                'bool' => [
                    'must' => [
                        ['bool' => ['should' => $periods]],
                    ],
                ],
            ],
        ]);

        return $this->periodsToAggs($result, new Request())['filtered_agg']['buckets'] ?? null;
    }

    private function getThematicallySimilarItems(array $record, string $recordId, string $type): array
    {
        $matches = [];

        if ($type === 'title') {
            foreach (($record['title'] ?? []) as $title) {
                if (!empty($title['text'])) {
                    $matches[] = ['match' => ['title.text' => PortalSearchUtils::escapeLuceneValue($title['text'])]];
                }
            }
        } elseif ($type === 'location') {
            $spatialMatches = [];

            foreach (($record['spatial'] ?? []) as $spatial) {
                if (!empty($spatial['placeName'])) {
                    $spatialMatches[] = ['match' => ['spatial.placeName' => trim((string) $spatial['placeName'])]];
                }
            }

            if (!empty($spatialMatches)) {
                $matches = [[
                    'nested' => [
                        'path' => 'spatial',
                        'query' => ['bool' => ['should' => $spatialMatches]],
                    ],
                ]];
            }
        } elseif ($type === 'subject') {
            foreach (($record['nativeSubject'] ?? []) as $subject) {
                if (!empty($subject['prefLabel'])) {
                    $matches[] = ['match' => ['nativeSubject.prefLabel' => trim((string) $subject['prefLabel'])]];
                }
            }
        } elseif ($type === 'temporal') {
            $temporalMatches = [];

            foreach (($record['temporal'] ?? []) as $temporal) {
                if (!empty($temporal['periodName'])) {
                    $temporalMatches[] = ['match' => ['temporal.periodName.raw' => trim((string) $temporal['periodName'])]];
                }
            }

            if (!empty($temporalMatches)) {
                $matches = [[
                    'nested' => [
                        'path' => 'temporal',
                        'query' => ['bool' => ['should' => $temporalMatches]],
                    ],
                ]];
            }
        } else {
            foreach (($record['nativeSubject'] ?? []) as $subject) {
                if (!empty($subject['prefLabel'])) {
                    $matches[] = ['match' => ['nativeSubject.prefLabel' => trim((string) $subject['prefLabel'])]];
                }
            }

            $temporalMatches = [];
            foreach (($record['temporal'] ?? []) as $temporal) {
                if (!empty($temporal['periodName'])) {
                    $temporalMatches[] = ['match' => ['temporal.periodName.raw' => trim((string) $temporal['periodName'])]];
                }
            }

            if (!empty($temporalMatches)) {
                $matches[] = [
                    'nested' => [
                        'path' => 'temporal',
                        'query' => ['bool' => ['should' => $temporalMatches]],
                    ],
                ];
            }
        }

        if (empty($matches)) {
            return [];
        }

        $result = $this->searchRecords([
            '_source' => ['title', 'ariadneSubject'],
            'size' => 7,
            'query' => [
                'bool' => [
                    'must_not' => [['match' => ['_id' => $recordId]]],
                    'should' => $matches,
                    'minimum_should_match' => 1,
                ],
            ],
        ]);

        $resources = [];

        foreach (Arr::get($result, 'hits.hits', []) as $hit) {
            $normalized = PortalSearchUtils::splitLanguages(Arr::get($hit, '_source', []), $this->defaultLanguage());
            $resources[] = [
                'id' => $hit['_id'],
                'type' => Arr::get($hit, '_source.ariadneSubject'),
                'title' => $normalized['title'] ?? [],
            ];
        }

        return $resources;
    }

    private function getItemsPartOf(array $record): ?array
    {
        $parts = [];

        foreach (($record['isPartOf'] ?? []) as $part) {
            $parts[] = ['match' => ['identifier' => $part]];
        }

        if (empty($parts)) {
            return null;
        }

        $result = $this->searchRecords([
            '_source' => ['title'],
            'query' => ['bool' => ['should' => $parts]],
        ]);

        $resources = [];

        foreach (Arr::get($result, 'hits.hits', []) as $hit) {
            $normalized = PortalSearchUtils::splitLanguages(Arr::get($hit, '_source', []), $this->defaultLanguage());
            $resources[] = ['id' => $hit['_id'], 'title' => $normalized['title'] ?? ''];
        }

        return $resources;
    }

    private function getCollectionItems(array $record): array
    {
        if (($record['resourceType'] ?? null) !== 'collection') {
            return [];
        }

        $result = $this->searchRecords([
            '_source' => ['title'],
            'size' => 7,
            'query' => ['match_phrase' => ['isPartOf' => $record['identifier'] ?? '']],
        ]);

        $hits = [];

        foreach (Arr::get($result, 'hits.hits', []) as $hit) {
            $normalized = PortalSearchUtils::splitLanguages(Arr::get($hit, '_source', []), $this->defaultLanguage());
            $hits[] = ['id' => $hit['_id'], 'title' => $normalized['title'] ?? []];
        }

        return [
            'total' => (int) Arr::get($result, 'hits.total.value', 0),
            'hits' => $hits,
        ];
    }

    private function getSubSubjects(string $id): array
    {
        $result = $this->searchSubjects([
            '_source' => ['prefLabel'],
            'size' => 100,
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'term' => [
                                'broader.id' => ['value' => $id],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $subjects = [];

        foreach (Arr::get($result, 'hits.hits', []) as $subject) {
            if (!empty($subject['_source']['prefLabel'])) {
                $subjects[] = [
                    'id' => $subject['_id'],
                    'prefLabel' => $subject['_source']['prefLabel'],
                ];
            }
        }

        return $subjects;
    }

    private function includeRegexp(string $query): string
    {
        $parts = preg_split('/[\s]+/', $query) ?: [];
        $regexp = '';

        foreach ($parts as $value) {
            $regexp .= '(.*'.strtolower($value).'.*|.*'.ucfirst($value).'.*|.*'.strtoupper($value).'.*)';
        }

        return $regexp;
    }

    private function firstSpatialPoint(array $record): ?array
    {
        foreach (($record['spatial'] ?? []) as $spatial) {
            if (!empty($spatial['geopoint']['lat']) && !empty($spatial['geopoint']['lon'])) {
                return [
                    'lat' => (float) $spatial['geopoint']['lat'],
                    'lon' => (float) $spatial['geopoint']['lon'],
                ];
            }

            if (!empty($spatial['centroid']['lat']) && !empty($spatial['centroid']['lon'])) {
                return [
                    'lat' => (float) $spatial['centroid']['lat'],
                    'lon' => (float) $spatial['centroid']['lon'],
                ];
            }
        }

        return null;
    }

    private function simpleIndexListing(string $index): array
    {
        $response = $this->request('POST', '/'.$index.'/_search', [
            'size' => 10000,
            'sort' => [['id' => ['order' => 'asc']]],
            'query' => ['match_all' => new stdClass()],
        ]);

        return array_map(
            static fn (array $hit): array => $hit['_source'] ?? [],
            Arr::get($response, 'hits.hits', [])
        );
    }

    private function searchRecords(array $payload): array
    {
        return PortalSearchUtils::normalizeAggs(
            $this->request('POST', '/'.$this->recordsIndex().'/_search', $payload),
            []
        );
    }

    private function searchSubjects(array $payload): array
    {
        return $this->request('POST', '/'.$this->subjectsIndex().'/_search', $payload);
    }

    private function searchPeriods(array $payload): array
    {
        return $this->request('POST', '/'.$this->periodsIndex().'/_search', $payload);
    }

    private function get(string $path, bool $allow404 = false): array
    {
        return $this->request('GET', $path, [], $allow404);
    }

    private function post(string $path, array $payload): array
    {
        return $this->request('POST', $path, $payload);
    }

    private function request(string $method, string $path, array $payload = [], bool $allow404 = false): array
    {
        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->withBody($payload ? json_encode($payload, JSON_UNESCAPED_SLASHES) : '', 'application/json')
                ->send($method, $this->baseUrl().$path);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('OpenSearch is unreachable: '.$exception->getMessage(), previous: $exception);
        }

        if ($allow404 && $response->status() === 404) {
            return ['found' => false];
        }

        if ($response->failed()) {
            throw new RuntimeException("OpenSearch request failed for {$path}: ".$response->body());
        }

        return $response->json() ?? [];
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->baseUrl ?? env('OPENSEARCH_URL', 'http://127.0.0.1:9200')), '/');
    }

    private function recordsIndex(): string
    {
        return (string) ($this->recordsIndex ?? env('OPENSEARCH_RECORDS_INDEX', 'ariadne_portal'));
    }

    private function subjectsIndex(): string
    {
        return (string) ($this->subjectsIndex ?? env('OPENSEARCH_SUBJECTS_INDEX', 'ariadne_subjects'));
    }

    private function periodsIndex(): string
    {
        return (string) ($this->periodsIndex ?? env('OPENSEARCH_PERIODS_INDEX', 'ariadne_periods'));
    }

    private function servicesIndex(): string
    {
        return (string) ($this->servicesIndex ?? env('OPENSEARCH_SERVICES_INDEX', 'ariadne_services'));
    }

    private function publishersIndex(): string
    {
        return (string) ($this->publishersIndex ?? env('OPENSEARCH_PUBLISHERS_INDEX', 'ariadne_publishers'));
    }

    private function aatTermDescendantsIndex(): string
    {
        return (string) ($this->aatTermDescendantsIndex ?? env('OPENSEARCH_AAT_DESCENDANTS_INDEX', 'ariadne_aat_term_descendants'));
    }

    private function defaultLanguage(): string
    {
        return (string) ($this->defaultLanguage ?? env('PORTAL_DEFAULT_LANGUAGE', 'en'));
    }

    private function mapMarkerThreshold(): int
    {
        return (int) ($this->mapMarkerThreshold ?? env('PORTAL_MAP_MARKER_THRESHOLD', 500));
    }
}
