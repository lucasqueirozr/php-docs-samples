<?php

/**
 * Copyright 2018 Google Inc.
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

/**
 * For instructions on how to run the samples:
 *
 * @see https://github.com/GoogleCloudPlatform/php-docs-samples/tree/master/dlp/README.md
 */

// Include Google Cloud dependendencies using Composer
require_once __DIR__ . '/../vendor/autoload.php';

if (count($argv) != 9) {
    return print("Usage: php l_diversity.php CALLING_PROJECT DATA_PROJECT TOPIC SUBSCRIPTION DATASET TABLE SENSITIVE_ATTRIBUTE QUASI_ID_NAMES\n");
}
list($_, $callingProjectId, $dataProjectId, $topicId, $subscriptionId, $datasetId, $tableId, $sensitiveAttribute, $quasiIdNames) = $argv;
// Convert comma-separated list to arrays
$quasiIdNames = explode(',', $quasiIdNames);

# [START dlp_l_diversity]
/**
 * Computes the l-diversity of a column set in a Google BigQuery table.
 */
use Google\Cloud\Dlp\V2\DlpServiceClient;
use Google\Cloud\Dlp\V2\RiskAnalysisJobConfig;
use Google\Cloud\Dlp\V2\BigQueryTable;
use Google\Cloud\Dlp\V2\DlpJob\JobState;
use Google\Cloud\Dlp\V2\Action;
use Google\Cloud\Dlp\V2\Action\PublishToPubSub;
use Google\Cloud\Dlp\V2\PrivacyMetric\LDiversityConfig;
use Google\Cloud\Dlp\V2\PrivacyMetric;
use Google\Cloud\Dlp\V2\FieldId;
use Google\Cloud\PubSub\PubSubClient;

/** Uncomment and populate these variables in your code */
// $callingProjectId = 'The project ID to run the API call under';
// $dataProjectId = 'The project ID containing the target Datastore';
// $topicId = 'The name of the Pub/Sub topic to notify once the job completes';
// $subscriptionId = 'The name of the Pub/Sub subscription to use when listening for job';
// $datasetId = 'The ID of the dataset to inspect';
// $tableId = 'The ID of the table to inspect';
// $sensitiveAttribute = 'The column to measure l-diversity relative to, e.g. "firstName"';
// $quasiIdNames = ['array columns that form a composite key (quasi-identifiers)'];

// Instantiate a client.
$dlp = new DlpServiceClient([
    'projectId' => $callingProjectId,
]);
$pubsub = new PubSubClient([
    'projectId' => $callingProjectId,
]);
$topic = $pubsub->topic($topicId);

// Construct risk analysis config
$quasiIds = array_map(
    function ($id) {
        return (new FieldId())->setName($id);
    },
    $quasiIdNames
);

$sensitiveField = (new FieldId())
    ->setName($sensitiveAttribute);

$statsConfig = (new LDiversityConfig())
    ->setQuasiIds($quasiIds)
    ->setSensitiveAttribute($sensitiveField);

$privacyMetric = (new PrivacyMetric())
    ->setLDiversityConfig($statsConfig);

// Construct items to be analyzed
$bigqueryTable = (new BigQueryTable())
    ->setProjectId($dataProjectId)
    ->setDatasetId($datasetId)
    ->setTableId($tableId);

// Construct the action to run when job completes
$pubSubAction = (new PublishToPubSub())
    ->setTopic($topic->name());

$action = (new Action())
    ->setPubSub($pubSubAction);

// Construct risk analysis job config to run
$riskJob = (new RiskAnalysisJobConfig())
    ->setPrivacyMetric($privacyMetric)
    ->setSourceTable($bigqueryTable)
    ->setActions([$action]);

// Listen for job notifications via an existing topic/subscription.
$subscription = $topic->subscription($subscriptionId);

// Submit request
$parent = $dlp->projectName($callingProjectId);
$job = $dlp->createDlpJob($parent, [
    'riskJob' => $riskJob
]);

// Poll via Pub/Sub until job finishes
while (true) {
    foreach ($subscription->pull() as $message) {
        if (isset($message->attributes()['DlpJobName']) &&
            $message->attributes()['DlpJobName'] === $job->getName()) {
            $subscription->acknowledge($message);
            break 2;
        }
    }
}

// Get the updated job
$job = $dlp->getDlpJob($job->getName());

// Sleep to avoid race condition with the job's status.
while ($job->getState() == JobState::RUNNING) {
    usleep(1000000);
    $job = $dlp->getDlpJob($job->getName());
}

// Helper function to convert Protobuf values to strings
$value_to_string = function ($value) {
    $json = json_decode($value->serializeToJsonString(), true);
    return array_shift($json);
};

// Print finding counts
printf('Job %s status: %s' . PHP_EOL, $job->getName(), $job->getState());
switch ($job->getState()) {
    case JobState::DONE:
        $histBuckets = $job->getRiskDetails()->getLDiversityResult()->getSensitiveValueFrequencyHistogramBuckets();

        foreach ($histBuckets as $bucketIndex => $histBucket) {
            // Print bucket stats
            printf('Bucket %s:' . PHP_EOL, $bucketIndex);
            printf(
                '  Bucket size range: [%s, %s]' . PHP_EOL,
                $histBucket->getSensitiveValueFrequencyLowerBound(),
                $histBucket->getSensitiveValueFrequencyUpperBound()
            );

            // Print bucket values
            foreach ($histBucket->getBucketValues() as $percent => $valueBucket) {
                printf(
                    '  Class size: %s' . PHP_EOL,
                    $valueBucket->getEquivalenceClassSize()
                );

                // Pretty-print quasi-ID values
                print('  Quasi-ID values: {');
                foreach ($valueBucket->getQuasiIdsValues() as $index => $value) {
                    print(($index !== 0 ? ', ' : '') . $value_to_string($value));
                }
                print('}' . PHP_EOL);

                // Pretty-print sensitive values
                $topValues = $valueBucket->getTopSensitiveValues();
                foreach ($topValues as $topValue) {
                    printf(
                        '  Sensitive value %s occurs %s time(s).' . PHP_EOL,
                        $value_to_string($topValue->getValue()),
                        $topValue->getCount()
                    );
                }
            }
        }
        break;
    case JobState::FAILED:
        printf('Job %s had errors:' . PHP_EOL, $job->getName());
        $errors = $job->getErrors();
        foreach ($errors as $error) {
            var_dump($error->getDetails());
        }
        break;
    default:
        printf('Unexpected job state. Most likely, the job is either running or has not yet started.');
}
# [END dlp_l_diversity]
