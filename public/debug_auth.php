<?php
$url = 'https://novatv.novadevlabs.com/api/auth/stream';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true); // Get headers
// Provide headers that Laravel looks for
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-Stream-Username: yansinkrad',
    'X-Stream-Password: yansinkrad',
    'X-Stream-Id: 54416.ts'
]);

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpcode\n";
echo "Response:\n$response\n";
