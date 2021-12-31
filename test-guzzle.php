<?php

require_once __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

$client = new Client( [
    'base_uri' => 'http://www.google.co.kr' ,
    'timeout'  => 2.0 ,
] );

$response = $client->request( 'GET', '/' );
echo $response->getStatusCode() . "\n" ;

$request = new Request( 'GET', 'http://www.google.co.kr' );
$response = $client->send( $request, [ 'timeout' => 2.0 ] );
echo $response->getReasonPhrase() . "\n" ;
echo $response->getBody() . '\n' ;


?>