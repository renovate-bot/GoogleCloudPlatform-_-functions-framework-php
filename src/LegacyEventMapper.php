<?php

/**
 * Copyright 2020 Google LLC.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Google\CloudFunctions;

use RuntimeException;

class LegacyEventMapper
{
    // Maps background/legacy event types to their equivalent CloudEvent types.
    // For more info on event mappings see
    // https://github.com/GoogleCloudPlatform/functions-framework-conformance/blob/main/docs/mapping.md
    private static $ceTypeMap = [
        'google.pubsub.topic.publish' => 'google.cloud.pubsub.topic.v1.messagePublished',
        'providers/cloud.pubsub/eventTypes/topic.publish' => 'google.cloud.pubsub.topic.v1.messagePublished',
        'google.storage.object.finalize' => 'google.cloud.storage.object.v1.finalized',
        'google.storage.object.delete' => 'google.cloud.storage.object.v1.deleted',
        'google.storage.object.archive' => 'google.cloud.storage.object.v1.archived',
        'google.storage.object.metadataUpdate' => 'google.cloud.storage.object.v1.metadataUpdated',
        'providers/cloud.firestore/eventTypes/document.write' => 'google.cloud.firestore.document.v1.written',
        'providers/cloud.firestore/eventTypes/document.create' => 'google.cloud.firestore.document.v1.created',
        'providers/cloud.firestore/eventTypes/document.update' => 'google.cloud.firestore.document.v1.updated',
        'providers/cloud.firestore/eventTypes/document.delete' => 'google.cloud.firestore.document.v1.deleted',
        'providers/firebase.auth/eventTypes/user.create' => 'google.firebase.auth.user.v1.created',
        'providers/firebase.auth/eventTypes/user.delete' => 'google.firebase.auth.user.v1.deleted',
        'providers/google.firebase.analytics/eventTypes/event.log' => 'google.firebase.analytics.log.v1.written',
        'providers/google.firebase.database/eventTypes/ref.create' => 'google.firebase.database.ref.v1.created',
        'providers/google.firebase.database/eventTypes/ref.write' => 'google.firebase.database.ref.v1.written',
        'providers/google.firebase.database/eventTypes/ref.update' => 'google.firebase.database.ref.v1.updated',
        'providers/google.firebase.database/eventTypes/ref.delete' => 'google.firebase.database.ref.v1.deleted',
        'providers/cloud.storage/eventTypes/object.change' => 'google.cloud.storage.object.v1.finalized',
    ];

    // Constants for Legacy Pubsub Conversion
    private const PUBSUB_CE_EVENT_TYPE = 'google.pubsub.topic.publish';
    private const LEGACY_PUBSUB_MESSAGE_TYPE = 'type.googleapis.com/google.pubsub.v1.PubsubMessage';

    // CloudEvent service names.
    private const FIREBASE_AUTH_CE_SERVICE = 'firebaseauth.googleapis.com';
    private const FIREBASE_CE_SERVICE = 'firebase.googleapis.com';
    private const FIREBASE_DB_CE_SERVICE = 'firebasedatabase.googleapis.com';
    private const FIRESTORE_CE_SERVICE = 'firestore.googleapis.com';
    private const PUBSUB_CE_SERVICE = 'pubsub.googleapis.com';
    private const STORAGE_CE_SERVICE = 'storage.googleapis.com';

    // Maps background event services to their equivalent CloudEvent services.
    private static $ceServiceMap = [
        'providers/cloud.firestore/' => self::FIRESTORE_CE_SERVICE,
        'providers/google.firebase.analytics/' => self::FIREBASE_CE_SERVICE,
        'providers/firebase.auth/' => self::FIREBASE_AUTH_CE_SERVICE,
        'providers/google.firebase.database/' => self::FIREBASE_DB_CE_SERVICE,
        'providers/cloud.pubsub/' => self::PUBSUB_CE_SERVICE,
        'providers/cloud.storage/' => self::STORAGE_CE_SERVICE,
    ];

    // Maps CloudEvent service strings to regular expressions used to split a background
    // event resource string into CloudEvent resource and subject strings. Each regex
    // must have exactly two capture groups: the first for the resource and the second
    // for the subject.
    private static $ceResourceRegexMap = [
        self::FIREBASE_CE_SERVICE => '#^(projects/[^/]+)/(events/[^/]+)$#',
        self::FIREBASE_DB_CE_SERVICE => '#^projects/_/(instances/[^/]+)/(refs/.+)$#',
        self::FIRESTORE_CE_SERVICE => '#^(projects/[^/]+/databases/\(default\))/(documents/.+)$#',
        self::STORAGE_CE_SERVICE => '#^(projects/_/buckets/[^/]+)/(objects/.+)$#',
    ];

    // Maps Firebase Auth background event metadata field names to their equivalent
    // CloudEvent field names.
    private static $firebaseAuthMetadataFieldMap = [
        'createdAt' => 'createTime',
        'lastSignedInAt' => 'lastSignInTime',
    ];

    public function fromJsonData(array $jsonData, string $requestUriPath): CloudEvent
    {
        [$context, $data] = $this->getLegacyEventContextAndData($jsonData, $requestUriPath);

        $eventType = $context->getEventType();
        $resourceName = $context->getResourceName();

        $ceId = $context->getEventId();

        // Mapped from eventType.
        $ceType = $this->ceType($eventType);

        // From context/resource/service, or mapped from eventType.
        $ceService = $context->getService() ?: $this->ceService($eventType);

        // Split the background event resource into a CloudEvent resource and subject.
        [$ceResource, $ceSubject] = $this->ceResourceAndSubject(
            $ceService,
            $resourceName,
            $context->getDomain()
        );

        $ceTime = $context->getTimestamp();

        if ($ceService === self::PUBSUB_CE_SERVICE) {
            // Handle Pub/Sub events.
            if (!is_array($data)) {
                $data = ['data' => $data];
            }
            $data['messageId'] = $context->getEventId();
            $data['publishTime'] = $context->getTimestamp();
            $data = ['message' => $data];
        } elseif ($ceService === self::FIREBASE_AUTH_CE_SERVICE) {
            // Handle Firebase Auth events.
            if (array_key_exists('metadata', $data)) {
                foreach (self::$firebaseAuthMetadataFieldMap as $old => $new) {
                    if (array_key_exists($old, $data['metadata'])) {
                        $data['metadata'][$new] = $data['metadata'][$old];
                        unset($data['metadata'][$old]);
                    }
                }
            }

            if (array_key_exists('uid', $data)) {
                $ceSubject = sprintf('users/%s', $data['uid']);
            }
        }

        return CloudEvent::fromArray([
            'id' => $ceId,
            'source' => sprintf('//%s/%s', $ceService, $ceResource),
            'specversion' => '1.0',
            'type' => $ceType,
            'datacontenttype' => 'application/json',
            'dataschema' => null,
            'subject' => $ceSubject,
            'time' => $ceTime,
            'data' => $data,
        ]);
    }

    private function getLegacyEventContextAndData(array $jsonData, string $requestUriPath): array
    {
        if ($this->isRawPubsubPayload($jsonData)) {
            $jsonData = $this->convertRawPubsubPayload($jsonData, $requestUriPath);
        }

        $data = $jsonData['data'] ?? null;

        if (array_key_exists('context', $jsonData)) {
            $context = $jsonData['context'];
        } else {
            unset($jsonData['data']);
            $context = $jsonData;
        }

        $context = Context::fromArray($context);

        return [$context, $data];
    }

    private function isRawPubsubPayload(array $jsonData): bool
    {
        return (!is_null($jsonData) &&
              !array_key_exists('context', $jsonData) &&
              array_key_exists('subscription', $jsonData) &&
              array_key_exists('message', $jsonData) &&
              array_key_exists('data', $jsonData['message']) &&
              array_key_exists('messageId', $jsonData['message']));
    }

    private function convertRawPubsubPayload(array $jsonData, string $requestUriPath): array
    {
        $path_match = preg_match('#projects/[^/?]+/topics/[^/?]+#', $requestUriPath, $matches);
        if ($path_match) {
            $topic = $matches[0];
        } else {
            $topic = 'UNKNOWN_PUBSUB_TOPIC';
            $this->stderrStructuredWarn('Failed to extract the topic name from the URL path.');
            $this->stderrStructuredWarn(
                'Configure your subscription\'s push endpoint to use the following path: ' .
              'projects/PROJECT_NAME/topics/TOPIC_NAME'
            );
        }

        if (array_key_exists('publishTime', $jsonData['message'])) {
            $timestamp = $jsonData['message']['publishTime'];
        } else {
            $timestamp = gmdate('%Y-%m-%dT%H:%M:%S.%6NZ');
        }

        return [
            'context' => [
                'eventId' => $jsonData['message']['messageId'],
                'timestamp' => $timestamp,
                'eventType' => self::PUBSUB_CE_EVENT_TYPE,
                'resource' => [
                    'service' => self::PUBSUB_CE_SERVICE,
                    'type' => self::LEGACY_PUBSUB_MESSAGE_TYPE,
                    'name' => $topic,
                ],
            ],
            'data' => [
                '@type' => self::LEGACY_PUBSUB_MESSAGE_TYPE,
                'data' => $jsonData['message']['data'],
                'attributes' => $jsonData['message']['attributes'],
            ],
        ];
    }

    private function ceType(string $eventType): string
    {
        if (isset(self::$ceTypeMap[$eventType])) {
            return self::$ceTypeMap[$eventType];
        }

        // Default to the legacy event type if no mapping is found.
        return $eventType;
    }

    private function ceService(string $eventType): string
    {
        foreach (self::$ceServiceMap as $prefix => $ceService) {
            if (0 === strpos($eventType, $prefix)) {
                return $ceService;
            }
        }

        // Default to the legacy event type if no service mapping is found.
        return $eventType;
    }

    private function ceResourceAndSubject(string $ceService, string $resource, ?string $domain): array
    {
        if (!array_key_exists($ceService, self::$ceResourceRegexMap)) {
            return [$resource, null];
        }

        $ret = preg_match(self::$ceResourceRegexMap[$ceService], $resource, $matches);
        if (!$ret) {
            throw new RuntimeException(
                $ret === 0 ? 'Resource regex did not match' : 'Failed while matching resource regex'
            );
        }
        if (self::FIREBASE_DB_CE_SERVICE === $ceService) {
            if (null === $domain) {
                return [null, null];
            }
            $location = 'us-central1';
            if ($domain !== 'firebaseio.com') {
                preg_match('#^([\w-]+)\.#', $domain, $locationMatches);
                if (!$locationMatches) {
                    return [null, null];
                }
                $location = $locationMatches[1];
            }
            return ["projects/_/locations/$location/$matches[1]", $matches[2]];
        }

        return [$matches[1], $matches[2]];
    }

    private function stderrStructuredWarn(string $msg)
    {
        $stderr = fopen('php://stderr', 'wb');
        fwrite($stderr, json_encode([
            'message' => $msg,
            'severity' => 'WARNING'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fclose($stderr);
    }
}
