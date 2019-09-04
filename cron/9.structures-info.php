<?php

require_once "../init.php";


$guzzler = new Guzzler(10);

$rows = $mdb->find("structures", [], ['lastChecked' => -1]);
$time = date('Hi');
while (date('Hi') == $time && sizeof($rows) > 0) {
    $row = array_pop($rows);
    if ($row['lastChecked'] >= (time() - 86400)) break;

    $structID = $row['structure_id'];
    $scope = null;
    if (isset($row['corpID'])) { 
        $scopes = $mdb->find("scopes", ['scope' => 'esi-universe.read_structures.v1', 'corporation_id' => $row['corpID']]);
        if (sizeof($scopes)) $scope = $scopes[array_rand($scopes)];
        else continue; // TODO - Maybe check for alliance members?
    }
    if ($scope == null) { 
        $scopes = $mdb->find("scopes", ['scope' => 'esi-universe.read_structures.v1']);
        $scope = $scopes[array_rand($scopes)];
    }

    $params = ['mdb' => $mdb, 'row' => $row, 'id' => $structID];
    CrestSSO::getAccessTokenCallback($guzzler, $scope['refreshToken'], "accessTokenDone", "accessTokenFail", $params);
}
$guzzler->finish();

function accessTokenDone($guzzler, $params, $content) {
    global $esiServer;

    $response = json_decode($content, true);
    $accessToken = $response['access_token'];
    //$params['content'] = $content;
    $row = $params['row'];
    $mdb = $params['mdb'];
    $structID = $params['id'];

    $headers = [];
    $headers['Content-Type'] = 'application/json';
    $headers['Authorization'] = "Bearer $accessToken";
    $headers['etag'] = true;

    $params = ['row' => $row, 'mdb' => $mdb];

    $route = "$esiServer/v2/universe/structures/$structID/";
    $guzzler->call($route, "success", "fail", $params, $headers, 'GET');
}

function accessTokenFail($guzzler, $params, $ex) {
    echo "failed...\n";
}


function success($guzzler, $params, $content) {
    $mdb = $params['mdb'];
    $row = $params['row'];

    if ($content == "") {
        $mdb->set("structures", $row, ['lastChecked' => time()]);
echo "Etagged\n";
        return;
    }

    $data = json_decode($content, true);

    $row = array_merge($data, $row);
    $row['lastChecked'] = time();
    $row['hasMatch'] = true;

    $mdb->save("structures", $row);
    Util::out("Added " . $row['name']);
}

function fail($guzzer, $params, $ex) {
    $code = $ex->getCode();

    $row = $params['row'];
    $mdb = $params['mdb'];

    switch ($code) {
        case 403:
        case 404:
            // Did this die or is private now?
            echo "Wanting to remove: " . print_r($row, true) . " \n";
            $mdb->remove("structures", $row);
            break;
        default:
            print_r($ex);
            $guzzler->finish();
            die();
    }
}