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
<html lang="fr" class="bg-white">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer" />
    <link href="https://unpkg.com/tailwindcss@^2/dist/tailwind.min.css" rel="stylesheet">
    <title><?php echo CONFIG['title'] ?></title>
</head>
<body>
<div class="px-4">
    <div class="py-4 px-4">
        <div class="text-center">
            <p class="mt-1 text-3xl font-extrabold text-gray-900">
                <?php echo CONFIG['title'] ?>
            </p>
            <p class="mt-1 mx-auto text-xl text-gray-500">
                <a href="<?php echo CONFIG['reservationUrl'] ?>"
                   class="text-sm font-medium text-indigo-600 hover:text-indigo-900" target="_blank">Réserver sur
                    Moffi</a>
            </p>
        </div>
    </div>
    <div>
        <?php foreach ($planning as $key => $week): ?>
            <div class="bg-gray-50 px-3 rounded-lg ring-1 ring-gray-300">
                <div>
                    <h3 class="text-base text-center font-semibold text-gray-600 tracking-wide pt-3">
                        <span class="uppercase">
                            Semaine n°<?php echo $week['weeknumber'] ?>
                        </span>
                        <?php if ($week['weeknumber'] == date('W')) : ?>
                            <span class="ml-2 inline-flex items-center px-2 py-0.5 border border-transparent text-xs font-medium rounded-full shadow-sm text-white bg-indigo-500">Semaine actuelle</span>
                        <?php endif ?>
                    </h3>
                </div>
                <div class="grid 2xl:grid-cols-5 lg:grid-cols-3 md:grid-cols-2 sm:grid-cols-1 gap-4 mb-8">
                    <?php foreach ($week['days'] as $daynumber => $day): ?>
                        <div class="flex flex-col py-4">
                            <div class="shadow overflow-hidden border sm:rounded-lg <?php echo date('Y-m-d', $day['date']) == date('Y-m-d') ? 'border-indigo-500' : 'border-gray-300' ?>">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="<?php echo date('Y-m-d', $day['date']) == date('Y-m-d') ? 'bg-indigo-100' : 'bg-gray-50' ?>">
                                    <tr>
                                        <th scope="col"
                                            class="px-6 py-3 text-left text-sm font-medium text-gray-700 uppercase tracking-wider">
                                            <?php echo strftime('%A %d %b', $day['date']) ?>
                                            <span class="text-xs text-gray-400">
                                                <?php echo '(' . count($day['presence']) . ' pers.)' ?>
                                            </span>
                                        </th>
                                    </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                    <?php $nobody = true; ?>
                                    <?php foreach ($day['presence'] as $person): ?>
                                        <?php $nobody = false; ?>
                                        <tr>
                                            <td class="px-4 py-2 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-10 w-10">
                                                        <img class="h-10 w-10 border border-grey-50 rounded-full"
                                                             src="<?php echo $person['avatar'] ?? 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($person['email']))) . '?s=200&d=robohash' ?>"
                                                             alt="">
                                                    </div>
                                                    <div class="ml-4">
                                                        <div class="text-sm font-medium text-gray-900">
                                                            <?php echo $person['firstname'] ?>
                                                            <span class="text-xs uppercase">
                                                            <?php echo $person['lastname'] ?>
                                                        </span>
                                                        </div>
                                                        <div class="text-xs text-gray-500">
                                                            <?php echo $person['email'] ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach ?>
                                    <?php if ($nobody && $date_end_reservation < $day['date']): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-center text-gray-500">
                                                    Non réservable actuellement
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif ?>
                                    </tbody>
                                    <tfoot class="<?php echo date('Y-m-d', $day['date']) == date('Y-m-d') ? 'bg-indigo-100' : 'bg-gray-50' ?>">
                                    <tr>
                                        <td class="px-6 py-2 whitespace-nowrap">
                                            <div class="text-xs text-center text-gray-600">
                                                Données du <?php echo strftime('%d/%m/%Y %H:%M', $day['filetime']) ?>
                                            </div>
                                        </td>
                                    </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    <?php endforeach ?>
                </div>
            </div>
        <?php endforeach ?>
    </div>
</div>
</body>
</html>
