<?php
if (!file_exists(__DIR__ . '/config.php')) {
    die('Missing configuration file');
}
require_once(__DIR__ . '/config.php');

$page = preg_replace('/^\//', '', $_SERVER['REQUEST_URI']);
$page = preg_replace('/\.php$/', '', $page);
if (!$page) {
    $page = 'index';
}
if (!isset($config[$page])) {
    header("HTTP/1.0 404 Not Found");
    echo 'C\'est pas le bon endroit';
    die;
}
define('CONFIG', $config[$page]);

setlocale(LC_TIME, "fr_FR.utf8");
function getPresence($date_str = '2021-10-04')
{
    $workspace = CONFIG['workspaceId'];
    $array = getFromApiOrCache('https://api.moffi.io/api/workspaces/' . $workspace . '/present?start=' . $date_str . 'T00:00:00.036Z&end=' . $date_str . 'T23:59:59.036Z');
    $filetime = $array[0];
    $json = $array[1];
    return [$filetime, json_decode($json, true)];
}

function getFromApiOrCache($url)
{
    $cache_path = 'cache/';

    # clear old expired cache
    if ($handle = opendir($cache_path)) {
        while (false !== ($file = readdir($handle))) {
            if (preg_match("/\.cache$/", $file)) {
                $filelastmodified = filemtime($cache_path . $file);
                if ((time() - $filelastmodified) > 24 * 3600) {
                    unlink($cache_path . $file);
                }
            }
        }
        closedir($handle);
    }

    # handle cache
    $cache_file = $cache_path . md5($url) . '.cache';
    if (file_exists($cache_file)) {
        if (time() - filemtime($cache_file) > (3600 + random_int(0, 3600))) {
            // too old, re-fetch
            $cache = file_get_contents($url);
            file_put_contents($cache_file, $cache);
            clearstatcache();
        } else {
            // cache is still fresh
        }
    } else {
        // no cache, create one
        $cache = file_get_contents($url);
        file_put_contents($cache_file, $cache);
        clearstatcache();
    }
    return [filemtime($cache_file), file_get_contents($cache_file)];
}

$start_date = strtotime('Monday this week');
$date_end_reservation = strtotime('+20 day');
$planning = [];
for ($i = 0; $i <= 27; $i++) {
    $date = strtotime('+' . $i . ' day', $start_date);
    $date_str = date('Y-m-d', $date);
    $weekday = (int)date('N', $date);
    if ($weekday == 6 or $weekday == 7) {
        continue;
    }
    $array = getPresence($date_str);
    $filetime = $array[0];
    $presence = $array[1];
    $names = array_column($presence, 'lastname');
    array_multisort($names, SORT_ASC, $presence);

    $weeknumber = date('W', $date);
    $year = date('Y', $date);
    $key = $year . $weeknumber;
    if (!isset($planning[$key])) {
        $planning[$key] = [
            'weeknumber' => $weeknumber,
            'days' => [],
        ];
    }
    $planning[$key]['days'][$weekday] = [
        'date' => $date,
        'presence' => $presence,
        'filetime' => $filetime,
    ];
}

?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.1/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-F3w7mX95PdgyTmZZMECAngseQB83DfGTowi0iMjiWaeVhAn4FJkqJByhZMI3AhiU" crossorigin="anonymous">
    <title><?php echo CONFIG['title'] ?></title>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <h1 class="text-center mt-3 mb-4">
                <?php echo CONFIG['title'] ?><br/>
                <small class="text-muted">Réservations des prochaines semaines</small> -
                <small><a href="<?php echo CONFIG['reservationUrl'] ?>" target="_blank">Réserver sur Moffi</a></small>
            </h1>
        </div>
    </div>
    <div class="row align-items-start">
        <?php foreach ($planning as $key => $week): ?>
            <div class="row align-items-start">
                <div class="col-lg-12 col-xl-2 text-center pt-4 pb-3">
                    <h5 class="text-muted">
                        Semaine
                    </h5>
                    <h3 class="card-title <?php echo ($week['weeknumber'] == date('W')) ? 'text-danger' : 'text-muted' ?>">
                        n°<?php echo $week['weeknumber'] ?>
                    </h3>
                    <?php if ($week['weeknumber'] == date('W')) : ?>
                        <p class="small text-danger">Semaine actuelle</p>
                    <?php endif ?>
                </div>
                <?php foreach ($week['days'] as $daynumber => $day): ?>
                    <div class="col-xs-12 col-sm-6 col-md-4 col-lg-4 col-xl-2">
                        <div class="card mb-3 small <?php echo date('Y-m-d', $day['date']) == date('Y-m-d') ? 'bg-light border-danger' : '' ?>">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <?php echo strftime('%A %d %B', $day['date']) ?>
                                    <small class="text-muted"><?php echo '(' . count($day['presence']) . ' pers.)' ?></small>
                                </h5>
                                <p class="card-text">
                                    <?php
                                    $nobody = true;
                                    foreach ($day['presence'] as $person) {
                                        echo $person['firstname'] . ' ' . $person['lastname'] . '<br />';
                                        echo '<small class="text-muted">(' . $person['email'] . ')</small><br />';
                                        $nobody = false;
                                    }
                                    ?>
                                    <small class="text-muted"><?php echo ($nobody && $date_end_reservation < $day['date']) ? 'Non réservable actuellement' : '' ?></small>
                                </p>
                                <p class="text-muted mb-0">
                                    <small>Données du <?php echo strftime('%d/%m/%Y %H:%M', $day['filetime']) ?></small>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endforeach ?>
            </div>
        <?php endforeach ?>
    </div>
</div>
</body>
</html>
