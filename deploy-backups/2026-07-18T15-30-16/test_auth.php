<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';

use Illuminate\Http\Request;
use App\Models\User;

// Register route on the application
$app->booted(function ($app) {
    $app['router']->get('/test-db', function() {
        $user = User::first();
        if (!$user) {
            return response()->json(['error' => 'No user found']);
        }
        $token = $user->createToken('test-token')->plainTextToken;
        return response()->json([
            'user' => $user->email,
            'token' => $token
        ]);
    });
});

// Handle the request using handleRequest
$request = Request::create('/test-db', 'GET');
$request->headers->set('Accept', 'application/json');

$response = $app->handleRequest($request);

echo "Status: " . $response->getStatusCode() . "\n";
echo "Content: " . $response->getContent() . "\n";
