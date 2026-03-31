<?php

namespace App\Support;

use SimpleXMLElement;

class PortalSearchUtils
{
    public static function escapeLuceneValue(string $value, bool $escapeSlashes = true): string
    {
        $lucene = '<>{}[]=&|!^?\\'.($escapeSlashes ? '/' : '');
        $value = str_replace(str_split($lucene), ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    public static function splitLanguages(array $resource, string $defaultLanguage): array
    {
        $fields = ['title', 'description'];
        $resourceLanguage = $resource['language'] ?? $defaultLanguage;

        foreach ($resource as $fieldName => $fieldData) {
            if (!in_array($fieldName, $fields, true) || !is_array($fieldData)) {
                continue;
            }

            if (!array_is_list($fieldData)) {
                $resource[$fieldName] = $fieldData;
                continue;
            }

            $result = [];

            if (empty($fieldData) || count($fieldData) === 1) {
                $result[$fieldName] = $fieldData[0] ?? null;
            } else {
                $defaultLanguageKey = array_search($defaultLanguage, array_column($fieldData, 'language'), true);

                if ($defaultLanguageKey !== false) {
                    $result[$fieldName] = $fieldData[$defaultLanguageKey];
                    unset($fieldData[$defaultLanguageKey]);
                } else {
                    $resourceLanguageKey = array_search($resourceLanguage, array_column($fieldData, 'language'), true);

                    if ($resourceLanguageKey !== false) {
                        $result[$fieldName] = $fieldData[$resourceLanguageKey];
                        unset($fieldData[$resourceLanguageKey]);
                    } else {
                        $result[$fieldName] = $fieldData[0];
                        unset($fieldData[0]);
                    }
                }

                if (!empty($fieldData)) {
                    $result[$fieldName.'Other'] = array_values($fieldData);
                }
            }

            $resource = array_replace($resource, $result);
        }

        return $resource;
    }

    public static function normalizeAggs(array $result, array $requestedBuckets): array
    {
        if (!isset($result['aggregations'])) {
            return $result;
        }

        foreach ($result['aggregations'] as $aggregationKey => $aggregationValue) {
            if (empty($requestedBuckets[$aggregationKey]) || !empty($aggregationValue['buckets'])) {
                continue;
            }

            foreach ($requestedBuckets[$aggregationKey] as $bucketValue) {
                $result['aggregations'][$aggregationKey]['buckets'][] = [
                    'key' => $bucketValue,
                    'doc_count' => 0,
                ];
            }
        }

        return $result;
    }

    public static function recordAsXml(array $record): string
    {
        $xml = new SimpleXMLElement('<root/>');
        self::arrayToXml($record, $xml);

        $dom = dom_import_simplexml($xml)->ownerDocument;
        $dom->formatOutput = true;

        return (string) $dom->saveXML();
    }

    private static function arrayToXml(array $data, SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            $element = is_numeric($key) ? 'item' : (string) $key;

            if (is_array($value)) {
                $child = $xml->addChild($element);
                self::arrayToXml($value, $child);
                continue;
            }

            $xml->addChild($element, htmlspecialchars((string) ($value ?? '')));
        }
    }
}
