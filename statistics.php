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

$numRequests = count( $pullRequests );
echo $numRequests . " open pull requests\n";
$totalTime = 0;

$connection = new \Domnikl\Statsd\Connection\UdpSocket( $host, 8125 );
$statsClient = new \Domnikl\Statsd\Client( $connection, "" ) ;

foreach ( $pullRequests as $pr ) {
    $time = DateTime::createFromFormat( 'Y-m-d\TH:i:s\Z', $pr['created_at'] );
    $interval = ( new DateTime )->getTimestamp() - $time->getTimestamp();
    $totalTime += $interval;
}

$average = round( $totalTime / $numRequests / 60 / 60, 1 );
echo 'Average open time: ' . $average . " hours\n";
$statsClient->gauge( 'github.prs.duration', $average );
