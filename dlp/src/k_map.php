<?php

/**
 * Copyright 2016 Google Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
namespace Google\Cloud\Samples\Dlp;

# [START k_map]
use Google\Cloud\Dlp\V2\DlpServiceClient;
use Google\Cloud\Dlp\V2\InfoType;
use Google\Cloud\Dlp\V2\RiskAnalysisJobConfig;
use Google\Cloud\Dlp\V2\BigQueryTable;
use Google\Cloud\Dlp\V2\DlpJob_JobState;
use Google\Cloud\PubSub\PubSubClient;
use Google\Cloud\Dlp\V2\Action;
use Google\Cloud\Dlp\V2\Action_PublishToPubSub;
use Google\Cloud\Dlp\V2\PrivacyMetric_KMapEstimationConfig;
use Google\Cloud\Dlp\V2\PrivacyMetric_KMapEstimationConfig_TaggedField;
use Google\Cloud\Dlp\V2\PrivacyMetric;
use Google\Cloud\Dlp\V2\FieldId;

/**
 * Computes the k-map risk estimation of a column set in a Google BigQuery table.
 *
 * @param string $callingProjectId The project ID to run the API call under
 * @param string $dataProjectId The project ID containing the target Datastore
 * @param string $topicId The name of the Pub/Sub topic to notify once the job completes
 * @param string $subscriptionId The name of the Pub/Sub subscription to use when listening for job
 * @param string $datasetId The ID of the dataset to inspect
 * @param string $tableId The ID of the table to inspect
 * @param string $regionCode The ISO 3166-1 region code that the data is representative of
 * @param array $quasiIdNames A set of columns that form a composite key ('quasi-identifiers'),
 *        and optionally their reidentification distributions
 * @param array $infoTypes The infoTypes corresponding to the chosen quasi-identifiers

 */
function k_map(
    $callingProjectId,
    $dataProjectId,
    $topicId,
    $subscriptionId,
    $datasetId,
    $tableId,
    $regionCode,
    $quasiIdNames,
    $infoTypes)
{
    // Instantiate a client.
    $dlp = new DlpServiceClient();
    $pubsub = new PubSubClient([
        'projectId' => $callingProjectId // TODO is this necessary?
    ]);

    // Verify input
    if (count($infoTypes) != count($quasiIdNames)) {
        throw new Exception('Number of infoTypes and number of quasi-identifiers must be equal!');
    }

    // Map infoTypes to quasi-ids
    $quasiIdObjects = array_map(function ($quasiId, $infoType) {
        $quasiIdField = new FieldId();
        $quasiIdField->setName($quasiId);

        $quasiIdType = new InfoType();
        $quasiIdType->setName($infoType);

        $quasiIdObject = new PrivacyMetric_KMapEstimationConfig_TaggedField();
        $quasiIdObject->setInfoType($quasiIdType);
        $quasiIdObject->setField($quasiIdField);

        return $quasiIdObject;
    }, $quasiIdNames, $infoTypes);

    // Construct analysis config
    $statsConfig = new PrivacyMetric_KMapEstimationConfig();
    $statsConfig->setQuasiIds($quasiIdObjects);
    $statsConfig->setRegionCode($regionCode);

    $privacyMetric = new PrivacyMetric();
    $privacyMetric->setKMapEstimationConfig($statsConfig);

    // Construct items to be analyzed
    $bigqueryTable = new BigQueryTable();
    $bigqueryTable->setProjectId($dataProjectId);
    $bigqueryTable->setDatasetId($datasetId);
    $bigqueryTable->setTableId($tableId);

    // Construct the action to run when job completes
    $fullTopicId = 'projects/' . $callingProjectId . '/topics/' . $topicId;
    $pubSubAction = new Action_PublishToPubSub();
    $pubSubAction->setTopic($fullTopicId);

    $action = new Action();
    $action->setPubSub($pubSubAction);

    // Construct risk analysis job config to run
    $riskJob = new RiskAnalysisJobConfig();
    $riskJob->setPrivacyMetric($privacyMetric);
    $riskJob->setSourceTable($bigqueryTable);
    $riskJob->setActions([$action]);

    // Listen for job notifications via an existing topic/subscription.
    $topic = $pubsub->topic($topicId);
    $subscription = $topic->subscription($subscriptionId);

    // Submit request
    $parent = $dlp->projectName($callingProjectId);
    $job = $dlp->createDlpJob($parent, [
        'riskJob' => $riskJob
    ]);

    // Poll via Pub/Sub until job finishes
    // TODO is there a better way to do this?
    $polling = true;
    while ($polling) {
        foreach ($subscription->pull() as $message) {
            $subscription->acknowledge($message);
            if (isset($message->attributes()['DlpJobName']) &&
                $message->attributes()['DlpJobName'] === $job->getName()) {
                $polling = false;
            }
        }
    }

    // Get the updated job
    $job = $dlp->getDlpJob($job->getName());

    // Helper function to convert Protobuf values to strings
    // TODO is there a better way?
    $value_to_string = function ($value) {
        return $value->getIntegerValue() ?:
            $value->getFloatValue() ?:
            $value->getStringValue() ?:
            $value->getBooleanValue() ?:
            $value->getTimestampValue() ?:
            $value->getTimeValue() ?:
            $value->getDateValue() ?:
            $value->getDayOfWeekValue();
    };

    // Print finding counts
    print_r('Job ' . $job->getName() . ' status: ' . $job->getState() . PHP_EOL);
    switch ($job->getState()) {
        case DlpJob_JobState::DONE:
            $histBuckets = $job->getRiskDetails()->getKMapEstimationResult()->getKMapEstimationHistogram();

            foreach ($histBuckets as $bucketIndex => $histBucket) {
                // Print bucket stats
                print_r('Bucket ' . $bucketIndex . ':' . PHP_EOL);
                print_r('  Anonymity range: [' .
                  $histBucket->getMinAnonymity() .
                  ', ' .
                  $histBucket->getMaxAnonymity() .
                  "]" . PHP_EOL
                );
                print_r('  Size: ' . $histBucket->getBucketSize() . PHP_EOL);

                // Print bucket values
                foreach ($histBucket->getBucketValues() as $percent => $valueBucket) {
                    print_r('  Estimated k-map anonymity: ' .
                      $valueBucket->getEstimatedAnonymity() . PHP_EOL
                    );

                    // Pretty-print quasi-ID values
                    // TODO better to use array_map and iterator_to_array here?
                    print_r('  Values: {');
                    foreach ($valueBucket->getQuasiIdsValues() as $index => $value) {
                        print_r(($index !== 0 ? ', ' : '') . $value_to_string($value));
                    }
                    print_r('}' . PHP_EOL);
                }
            }
            break;
        case DlpJob_JobState::FAILED:
            $errors = $job->getErrors();
            foreach ($errors as $error) {
                var_dump($error->getDetails());
            }
            print_r('Job ' . $job->getName() . ' had errors:' . PHP_EOL);
            break;
        default:
            print_r('Unknown job state. Most likely, the job is either running or has not yet started.');
    }
}
# [END k_map]