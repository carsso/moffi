<?php
if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    die('Missing configuration file');
}
require_once(__DIR__ . '/config.php');

$page = preg_replace('/\?.*$/', '', preg_replace('/^\//', '', $_SERVER['REQUEST_URI']));
$page = preg_replace('/\.php$/', '', $page);
if (!$page) {
    $page = 'index';
}
if (!isset($config[$page])) {
    http_response_code(404);
    echo 'This page does not exist';
    die;
}
if(empty($login['username']) || empty($login['password'])) {
    http_response_code(500);
    die('Missing login credentials in config file');
}
define('CONFIG', $config[$page]);
define('LOGIN', $login);
define('CACHE_DIR', __DIR__ . '/cache/');
define('MOFFI_API', 'https://gateway.moffi.io/api/');

define('SHOW_LAST_WEEK', isset($_GET['lastWeek']) and $_GET['lastWeek'] ? true : false);

function login()
{
    $cache_file = CACHE_DIR . 'bearer.cache';
    $bearer = null;
    if (file_exists($cache_file)) {
        $bearer = file_get_contents($cache_file);
    }

    if(!empty($bearer)) {
        define('BEARER', $bearer);
        $array = getFromApiOrCache('users/me', 60, true, 'GET', null, true);
        $filetime = $array[0];
        $json = $array[1];
        $me = json_decode($json, true);
        if($me && is_array($me) && $me['id']) {
            # login still valid
            return true;
        }
    }

    $array = [
        'captcha' => 'NOT_PROVIDED',
        'email' => LOGIN['username'],
        'password' => LOGIN['password'],
        'platform' => 'WEB',
    ];
    $content = json_encode($array);
    $array = getFromApiOrCache('auth/signin', 120, false, 'POST', $content);
    $filetime = $array[0];
    $json = $array[1];
    $signin = json_decode($json, true);

    if(!empty($signin['error'])) {
        http_response_code(500);
        die('Cannot login to Moffi: ' . $signin['error']);
    }
    if(empty($signin['accessToken'])) {
        http_response_code(500);
        die('No token found in signin response');
    }
    $bearer = $signin['accessToken'];
    file_put_contents($cache_file, $bearer);
    clearstatcache();

    define('BEARER', $bearer);
    header('X-NEW-MOFFI-LOGIN: true');

    // clear users/me cache
    getFromApiOrCache('users/me', 30, true, 'GET', null, false, true);
}

function getCoworking()
{
    $reservationUrl = CONFIG['reservationUrl'];
    if(!preg_match('/\/coworking\/(.*)$/', $reservationUrl, $matches)) {
        http_response_code(500);
        die('Invalid reservationUrl : '.$reservationUrl);
    }
    $array = getFromApiOrCache('workspaces/url/coworking/' . $matches[1], 60 * 60 * 12);
    $filetime = $array[0];
    $json = $array[1];
    $coworking = json_decode($json, true);
    if(!$coworking) {
        http_response_code(500);
        die('Coworking space not found');
    }
    return $coworking;
}

function getAvailabilities($dateStr = '2021-10-04')
{
    $workspaceId = COWORKING['id'];
    $buildingId = COWORKING['building']['id'];
    $floor = COWORKING['floor']['level'];

    $startDate = new DateTime($dateStr.' 00:00:01', new DateTimeZone(COWORKING['timezone']));
    $startDate->setTimezone(new DateTimeZone("UTC"));
    $startDateStr = $startDate->format("Y-m-d\TH:i:s.v\Z");

    $endDate = new DateTime($dateStr.' 23:59:59', new DateTimeZone(COWORKING['timezone']));
    $endDate->setTimezone(new DateTimeZone("UTC"));
    $endDateStr = $endDate->format("Y-m-d\TH:i:s.v\Z");
 
    $array = getFromApiOrCache('workspaces/availabilities?buildingId=' . $buildingId . '&startDate=' . $startDateStr . '&endDate=' . $endDateStr . '&places=1&floor=' . $floor . '&period=DAY&workspaceId=' . $workspaceId);
    $filetime = $array[0];
    $json = $array[1];
    return [$filetime, json_decode($json, true)];
}

function getFromApiOrCache($url, $duration = 1200, $injectLogin = true, $method = 'GET', $content = null, $doNotDie = false, $clearCache = false)
{
    if(empty($url)) {
        http_response_code(500);
        die('Empty url provided');
    }
    $url = MOFFI_API . $url;

    # clear old expired cache
    if ($handle = opendir(CACHE_DIR)) {
        while (false !== ($file = readdir($handle))) {
            if (preg_match("/\.cache$/", $file)) {
                $filelastmodified = filemtime(CACHE_DIR . $file);
                if ((time() - $filelastmodified) > 24 * 3600) {
                    unlink(CACHE_DIR . $file);
                }
            }
        }
        closedir($handle);
    }

    $headers = ['Content-Type: application/json'];
    if($injectLogin) {
        $headers[] = 'Authorization: Bearer '.BEARER;
    }

    $opts = [
        'http' => [
            'method' => $method,
            'header' => join("\r\n", $headers),
            'content' => $content,
        ]
    ];
    $context = stream_context_create($opts);

    # handle cache
    $cache_file = CACHE_DIR . md5($method.$url.json_encode($content)) . '.cache';
    if (!$clearCache && file_exists($cache_file) && (time() - filemtime($cache_file) <= ($duration + random_int(0, $duration)))) {
        // cache is still fresh
    }
    else
    {
        // too old or no cache, re-fetch/create cache
        $cache = file_get_contents($url, false, $context);
        preg_match('/([0-9])\d+/',$http_response_header[0],$matches);
        $responsecode = intval($matches[0]);
        file_put_contents($cache_file, $cache);
        clearstatcache();
        if($responsecode >= 300 && !$doNotDie)
        {
            // Errors are cached to avoid flooding
            echo 'A '.$responsecode.' error occured requesting '.$method.' '.$url."<br/>\n";
            echo 'Next try in '.$duration.'s'."<br/>\n";
            echo 'Response in '.$cache_file."\n";
            die();
        }
    }
    return [filemtime($cache_file), file_get_contents($cache_file), $cache_file];
}

login();
define('COWORKING', getCoworking());

$time = time();
if(SHOW_LAST_WEEK) {
  $time = strtotime('-7 days');
}

$start_date = strtotime('Monday this week', $time);
if((int)date('N') == 6 or (int)date('N') == 7) {
    $start_date = strtotime('Monday next week', $time);
}
$delay_days = !empty(COWORKING['plageMaxi']['minutes']) ? round(COWORKING['plageMaxi']['minutes']/60/24) : 30;
$date_end_reservation = strtotime('+'.$delay_days.' days');
$date_delay_days = strtotime('Sunday next week ', $date_end_reservation);
if($delay_days > 30) {
    $date_delay_days = strtotime('Sunday next week ', strtotime('+30 days'));
}
$planning = [];
for ($date = $start_date; $date <= $date_delay_days; $date += 24 * 3600) {
    $date_str = date('Y-m-d', $date);
    $weekday = (int)date('N', $date);
    if ($weekday == 6 or $weekday == 7) {
        continue;
    }
    $array = getAvailabilities($date_str);
    $filetime = $array[0];
    $availability = $array[1][0];
    $users = [];
    foreach($availability['seats'] as $seat) {
        if(!empty($seat['user'])) {
            if($seat['status'] == 'AGENDA') {
                continue;
            }
            $user = $seat['user'];
            $user['seat'] = $seat['seat']['fullname'];
            $users[] = $user;
        } elseif($seat['status'] == 'DISABLED') {
            if(!empty(CONFIG['showBlocked'])) {
                $name = $seat['seat']['name'];
                $name = preg_replace('/_LK$/', '', $name);
                $name = preg_replace('/_LK_/', '_', $name);
                $name = preg_replace('/^\d+_/', '', $name);
                if(strlen($name) > 3) {
                    $users[] = [
                        'id' => $seat['seat']['id'],
                        'firstname' => '',
                        'lastname' => $name,
                        'email' => strtolower($name.'_'.$seat['seat']['id']),
                        'type' => 'blocked',
                        'seat' => $seat['seat']['fullname'],
                    ];
                }
            }
        }
    }
    $names = array_column($users, 'email');
    array_multisort($names, SORT_ASC, $users);

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
        'users' => $users,
        'availability' => $availability['availability'],
        'availabilityStatus' => $availability['availabilityStatus'],
        'filetime' => $filetime,
    ];
}

?>
<!doctype html>
<html lang="fr" class="bg-gray-800">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer" />
    <meta http-equiv="refresh" content="600">
    <link href="https://unpkg.com/tailwindcss@^2/dist/tailwind.min.css" rel="stylesheet">
    <title><?php echo COWORKING['title'] ?></title>
</head>
<body>
<div class="px-4">
    <div class="py-4 px-4">
        <div class="text-center">
            <p class="mt-1 text-3xl font-extrabold text-gray-400">
                <?php echo COWORKING['title'] ?>
            </p>
            <p class="mt-1 mb-2 mx-auto text-gray-500 text-sm font-medium">
                Booking limit is <?php echo $delay_days ?> days in advance -
                <a href="<?php echo CONFIG['reservationUrl'] ?>"
                   class="text-purple-500 hover:text-purple-700" target="_blank">
                    Book on Moffi
                </a>
            </p>
        </div>
    </div>
    <div>
        <?php foreach ($planning as $key => $week): ?>
            <div class="bg-gray-700 px-3 rounded-lg ring-1 ring-gray-900">
                <div>
                    <h3 class="text-base text-center font-semibold text-gray-400 tracking-wide pt-3">
                        <span class="uppercase">
                            Week number <?php echo $week['weeknumber'] ?>
                        </span>
                        <?php if ($week['weeknumber'] == date('W')) : ?>
                            <span class="ml-2 inline-flex items-center px-2 py-0.5 border border-transparent text-xs font-medium rounded-full shadow-sm text-white bg-purple-600">Current week</span>
                            <?php if (!SHOW_LAST_WEEK): ?>
                              <small class="ml-2"><a href="?lastWeek=1" class="text-purple-500 font-normal hover:text-purple-700">Show last week</a></small>
                            <?php endif ?>
                        <?php endif ?>
                    </h3>
                </div>
                <div class="grid 2xl:grid-cols-5 lg:grid-cols-3 md:grid-cols-2 sm:grid-cols-1 gap-4 mb-8 py-4">
                    <?php foreach ($week['days'] as $daynumber => $day): ?>
                        <div class="flex flex-col">
                            <div class="shadow overflow-hidden border rounded-lg border-gray-900">
                                <table class="min-w-full divide-y divide-gray-900">
                                    <thead class="<?php echo (date('Y-m-d', $day['date']) == date('Y-m-d')) ? 'bg-purple-900' : 'bg-gray-800' ?>">
                                    <tr>
                                        <th scope="col"
                                            class="px-6 py-3 text-left text-sm font-medium uppercase tracking-wider <?php echo (date('Y-m-d', $day['date']) == date('Y-m-d')) ? 'text-gray-300' : 'text-gray-400' ?>">
                                            <?php echo date('l F j', $day['date']) ?>
                                            <span class="text-xs <?php echo (date('Y-m-d', $day['date']) == date('Y-m-d')) ? 'text-gray-400' : 'text-gray-600' ?>">
                                                <?php echo '(' . count($day['users']) . ' bookings)' ?>
                                            </span>
                                        </th>
                                    </tr>
                                    </thead>
                                    <tbody class="bg-gray-700 divide-y divide-gray-900">
                                    <?php $nobody = true; ?>
                                    <?php foreach ($day['users'] as $person): ?>
                                        <?php $nobody = false; ?>
                                        <tr title="<?php echo ($person['type'] == 'blocked') ? 'Blocked/Automatically booked' : $person['email'] ?>">
                                            <td class="px-4 py-2 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-5 w-5">
                                                        <img class="h-5 w-5 border border-gray-800 bg-gray-900 rounded-full"
                                                            src="<?php echo $person['avatar'] ?? 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($person['email']))) . '?s=50&d=robohash' ?>"
                                                            alt="">
                                                    </div>
                                                    <div class="ml-2">
                                                        <div class="text-sm font-medium <?php echo ($person['type'] == 'blocked') ? 'text-gray-900' : 'text-gray-400' ?>">
                                                            <?php echo $person['firstname'] ?>
                                                            <span class="text-xs uppercase">
                                                                <?php echo $person['lastname'] ?>
                                                            </span>
                                                            <small  class="text-gray-600">
                                                                (<?php echo $person['seat'] ?>)
                                                            </small>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach ?>
                                    <?php if ($nobody): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-center text-gray-900">
                                                    <?php if ($day['availability'] == 'UNAVAILABLE'): ?>
                                                        <?php if ($day['availabilityStatus'] == 'START_TOO_LATE'): ?>
                                                            Not bookable for now
                                                        <?php else: ?>
                                                            Not bookable
                                                        <?php endif ?>
                                                    <?php else: ?>
                                                        No booking
                                                    <?php endif ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif ?>
                                    </tbody>
                                    <tfoot class="<?php echo (date('Y-m-d', $day['date']) == date('Y-m-d')) ? 'bg-purple-900' : 'bg-gray-800' ?>">
                                    <tr>
                                        <td class="px-6 py-2 whitespace-nowrap">
                                            <div class="text-xs text-center <?php echo (date('Y-m-d', $day['date']) == date('Y-m-d')) ? 'text-gray-400' : 'text-gray-500' ?>">
                                                Data as of <?php echo date('Y-m-d H:i', $day['filetime']) ?>
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
