#!/usr/bin/env php
<?php
require_once __DIR__ . '/vendor/autoload.php';

if ( count ( $argv ) != 4 ) {
    echo "Usage: statistics.php <repo> <statsd host> <github api key>\n";
    die;
}

list( ,$repo, $host, $apiKey ) = $argv;
$credentials = base64_encode( "$apiKey:x-oauth-basic" );

$opts = [
    'http'=> [
        'user_agent' => 'php',
        'header' => "Authorization: Basic $credentials\n"
    ]
];

$ctx = stream_context_create( $opts );
$response = file_get_contents( "https://api.github.com/repos/$repo/pulls?state=open&per_page=1000", null, $ctx );
$pullRequests = json_decode( $response, true );

if ( !$pullRequests ) {
    echo "Error getting JSON\n";
    die;
}

echo count( $pullRequests ) . " open pull requests\n";

/**
 * @param array $pullRequests
 * @return float
 */
function getAverageAge( array $pullRequests )
{
    $numRequests = count( $pullRequests );
    $totalTime = 0;
    foreach ( $pullRequests as $pr ) {
        $time = DateTime::createFromFormat( 'Y-m-d\TH:i:s\Z', $pr['created_at'] );
        $interval = ( new DateTime )->getTimestamp() - $time->getTimestamp();
        $totalTime += $interval;
    }
    return round( $totalTime / $numRequests / 60 / 60, 1 );
}

/**
 * @param array $pullRequests
 * @param $ctx
 * @return float
 */
function getReviewability( array $pullRequests, $ctx )
{
    $totalPrs = count( $pullRequests );
    $totalLines = 0;
    foreach ( $pullRequests as $pr ) {
        $pr = json_decode( file_get_contents( $pr['url'], null, $ctx ), true );
        $totalLines += $pr['additions'] + $pr['deletions'];
    }
    $reviewability = floatval( $totalLines ) / $totalPrs;
    return $reviewability;
}

$connection = new \Domnikl\Statsd\Connection\UdpSocket( $host, 8125 );
$statsClient = new \Domnikl\Statsd\Client( $connection, "" ) ;
{
    $average = getAverageAge( $pullRequests );
    echo 'Average open time: ' . $average . " hours\n";
    $statsClient->gauge( 'github.prs.duration', $average );
}
{
    $average = getReviewability( $pullRequests, $ctx );
    echo "Average reviewometer: ", $average, "\n";
    $statsClient->gauge( 'github.prs.reviewability', $average );
}