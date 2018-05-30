<?php

require_once '../init.php';

use cvweiss\redistools\RedisTimeQueue;

if ($redis->get("zkb:reinforced") == true) exit();
$guzzler = new Guzzler();
$corps = new RedisTimeQueue("zkb:corporationID", 86400);

$minute = date('Hi');
while ($minute == date('Hi')) {
    $id = (int) $corps->next();
    if ($id <= 0) break;
    if ($id > 0) {
        $row = $mdb->findDoc("information", ['type' => 'corporationID', 'id' => $id]);

        $url = "$esiServer/v4/corporations/$id/";
        $params = ['mdb' => $mdb, 'redis' => $redis, 'row' => $row];
        $guzzler->call($url, "updateCorp", "failCorp", $params, ['etag' => true]);
    } else {
        $guzzler->tick();
        usleep(100000);
    }
}
$guzzler->finish();

function failCorp(&$guzzler, &$params, &$connectionException)
{
    $mdb = $params['mdb'];
    $code = $connectionException->getCode();
    $row = $params['row'];
    $id = $row['id'];

    switch ($code) {
        case 0: // timeout
        case 500:
        case 502: // ccp broke something
        case 503: // server error
        case 504: // gateway timeout
        case 200: // timeout...
            $mdb->set("information", $row, ['lastApiUpdate' => $mdb->now(86400 * -2)]);
            break;
        default:
            Util::out("/v4/corporations/ failed for $id with code $code");
    }
}

function updateCorp(&$guzzler, &$params, &$content)
{
    if ($content == "") return;

    $redis = $params['redis'];
    $mdb = $params['mdb'];
    $row = $params['row'];

    $json = json_decode($content, true);

    $ceoID = (int) $json['ceo_id'];

    $updates = [];
    compareAttributes($updates, "name", @$row['name'], (string) $json['name']);
    compareAttributes($updates, "ticker", @$row['ticker'], (string) $json['ticker']);
    compareAttributes($updates, "ceoID", @$row['ceoID'], $ceoID);
    compareAttributes($updates, "memberCount", @$row['memberCount'], (int) $json['member_count']);
    compareAttributes($updates, "allianceID", @$row['allianceID'], (int) @$json['alliance_id']); 
    compareAttributes($updates, "factionID", @$row['factionID'], (int) @$json['faction_id']);

    // Does the CEO exist in our info table?
    $ceoExists = $mdb->count('information', ['type' => 'characterID', 'id' => $ceoID]);
    if ($ceoExists == 0) {
        $mdb->insertUpdate('information', ['type' => 'characterID', 'id' => $ceoID], []);
    }

    if (sizeof($updates)) {
        $mdb->set("information", $row, $updates);
        $redis->del(Info::getRedisKey('corporationID', $row['id']));
    }
}

function compareAttributes(&$updates, $key, $oAttr, $nAttr) {
    if ($oAttr !== $nAttr) {
        $updates[$key] = $nAttr;
    }
}
