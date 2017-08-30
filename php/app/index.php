<?php
/**
 * REST API EndPoint
 *
 * Highload cup contest mail.ru
 *
 * @author Evgeniy Bogdanov
 */

use Phalcon\Mvc\Micro;
use Phalcon\Http\Response;
use Phalcon\Di\FactoryDefault;
use Phalcon\Http\Request;
use Phalcon\Cache\Backend\Memcache;
use Phalcon\Cache\Frontend\None as FrontData;

$di = new FactoryDefault();

// Set up the database service
$di->set(
    'db_users',
    function () {
        return new Tarantool();
    }
);

$di->set(
    'db_visits',
    function () {
        return new Tarantool('localhost', 3302);
    }
);

$di->set(
    'db_locations',
    function () {
        return new Tarantool('localhost', 3303);
    }
);

$di->set(
    'cache',
    function() {
        $frontCache = new FrontData();

        return new Memcache(
            $frontCache,
            [
                "host"       => "localhost",
                "port"       => 11211,
                "persistent" => false,
            ]
        );
    }
);

/*
    id - уникальный внешний идентификатор пользователя. Устанавливается тестирующей системой и используется для проверки ответов сервера. 32-разрядное целое беззнаковое число.
    email - адрес электронной почты пользователя. Тип - unicode-строка длиной до 100 символов. Уникальное поле.
    first_name и last_name - имя и фамилия соответственно. Тип - unicode-строки длиной до 50 символов.
    gender - unicode-строка m означает мужской пол, а f - женский.
    birth_date - дата рождения, записанная как число секунд от начала UNIX-эпохи по UTC (другими словами - это timestamp).
*/
$userFields     = array('id', 'email', 'first_name', 'last_name', 'gender', 'birth_date');

/*
    id - уникальный внешний id посещения. Устанавливается тестирующей системой. 32-разрядное целое беззнакое число.
    location - id достопримечательности. 32-разрядное целое беззнаковое число.
    user - id путешественника. 32-разрядное целое беззнаковое число.
    visited_at - дата посещения, timestamp.
    mark - оценка посещения от 0 до 5 включительно. Целое число.
 */
$visitFields    = array('id', 'location', 'user', 'visited_at', 'mark');

/*
    id - уникальный внешний id достопримечательности. Устанавливается тестирующей системой. 32-разрядное целое беззнаковоее число.
    place - описание достопримечательности. Текстовое поле неограниченной длины.
    country - название страны расположения. unicode-строка длиной до 50 символов.
    city - название города расположения. unicode-строка длиной до 50 символов.
    distance - расстояние от города по прямой в километрах. 32-разрядное целое беззнаковое число.
 */
$locationFields = array('id', 'place', 'country', 'city', 'distance');

$app = new Micro($di);

$app->get('/users/{id:[0-9]+}', function($id) use ($app, $userFields) {
        $response = new Response();

        $cacheKey = '/users/' . $id . '?';

        try {
            $tarantool = $app->di->get('db_users');
            $data = $tarantool->select('user', intval($id));

            if (!empty($data[0])) {
                $return = array_combine($userFields, $data[0]);

                $return = json_encode($return);

                $response->setContent($return);
                $response->setContentType('application/json');

                try {
                    $cache = $app->di->get('cache');
                    @$cache->save($cacheKey, $return, 20);
                } catch (Phalcon\Cache\Exception $e) {}

            } else {
                $response->setStatusCode(404);
            }
        } catch (\Exception $e) {
            $response->setStatusCode(500);
        }

        return $response;
    }
);

$app->get('/visits/{id:[0-9]+}', function($id) use ($app, $visitFields) {
        $response = new Response();
        $cacheKey = '/visits/' . $id . '?';

        try {
            $tarantool = $app->di->get('db_visits');
            $data = $tarantool->select('visit', intval($id));

            if (!empty($data[0])) {
                $return = array_combine($visitFields, $data[0]);

                $return = json_encode($return);

                $response->setContent($return);
                $response->setContentType('application/json');

                try {
                    $cache = $app->di->get('cache');
                    @$cache->save($cacheKey, $return, 20);
                } catch (Phalcon\Cache\Exception $e) {}

                return $response;
            } else {
                $response->setStatusCode(404);
            }
        } catch (\Exception $e) {
            $response->setStatusCode(500);
        }

        return $response;
    }
);

$app->get('/locations/{id:[0-9]+}', function($id) use ($app, $locationFields) {
        $response = new Response();

        $cacheKey = '/locations/' . $id . '?';

        try {
            $tarantool = $app->di->get('db_locations');
            $data = $tarantool->select('location', intval($id));

            if (!empty($data[0])) {
                $return = array_combine($locationFields, $data[0]);

                $return = json_encode($return);

                $response->setContent($return);
                $response->setContentType('application/json');

                try {
                    $cache = $app->di->get('cache');
                    @$cache->save($cacheKey, $return, 20);
                } catch (Phalcon\Cache\Exception $e) {}

            } else {
                $response->setStatusCode(404);
            }
        } catch (\Exception $e) {
            $response->setStatusCode(500);
        }

        return $response;
    }
);

$app->get('/users/{id:[0-9]+}/visits', function($id) use ($app) {
        $request = new Request();

        $fromDate    = $request->getQuery('fromDate');
        $toDate      = $request->getQuery('toDate');
        $country     = $request->getQuery('country');
        $toDistance  = $request->getQuery('toDistance');

        // fromDate - посещения с visited_at > fromDate
        // toDate - посещения до visited_at < toDate
        // country - название страны, в которой находятся интересующие достопримечательности
        // toDistance - возвращать только те места, у которых расстояние от города меньше этого параметра

        // Validate options
        $response = new Response();
        foreach (array('fromDate', 'toDate', 'toDistance') as $option) {
            if (isset($$option) && (!is_null($$option) && !is_numeric($$option))) {
                $response->setStatusCode(400);

                return $response;
            }
        }

        try {
            $tarantool = $app->di->get('db_users');
            $data = $tarantool->select('user', intval($id));
            $tarantool->close();

            // Return 404
            if (empty($data[0])) {
                $response->setStatusCode(404);

                return $response;
            } else {
                // Select visits
                $tarantool = $app->di->get('db_visits');
                // 1 - user_id
                $data = $tarantool->evaluate('return get_visit(...)', array(intval($data[0][0])));
                if (empty($data)) {
                    $response->setJsonContent(array('visits' => array()));
                    return $response;
                } else {
                    $data = array_pop($data);
                }

                // Filter data
                if (!is_null($fromDate) || !is_null($toDate)) {
                    $data = array_filter($data, function ($row) use ($fromDate, $toDate) {
                        if (!is_null($fromDate) && ($row[3] <= $fromDate)) {
                            return false;
                        }

                        if (!is_null($toDate) && ($row[3] >= $toDate)) {
                            return false;
                        }

                        return true;
                    });
                }

                // Ok, we've list if locations now.
                // Let's check them for country/distance
                $tarantool = $app->di->get('db_locations');

                $result = array();
                foreach ($data as $row) {
                    if (empty($country)) {
                        $return = $tarantool->evaluate('return get_locations(...)', array(array($row[1]), 'primary'));

                        if (empty($return)) {
                            continue;
                        }

                        $location = array_pop($return);
                        $location = array_pop($location);
                    } else {
                        $return = $tarantool->call('get_locations', array(array(array($row[1], $country)), 'with_country'));

                        if (empty($return)) {
                            continue;
                        }

                        $location = array_pop($return);
                    }

                    if (!empty($location) && (is_null($toDistance) || ($toDistance > $location[4]))) {
                        $result[] = array(
                            'mark'       => $row[4],
                            'visited_at' => $row[3],
                            'place'      => $location[1]
                        );
                    }
                }

                usort($result, function($a, $b) {
                    return $a['visited_at'] > $b['visited_at'];
                });
//
//                    /*$locationsData = empty($country)
//                        ? $tarantool->evaluate('return get_locations(...)', array($ids, 'primary'))
//                        : $tarantool->evaluate('return get_locations(...)', array($ids, 'with_country'));
//
//                    var_dump($locationsData); die;*/
//
//                    $tarantool->close();
//
//                    if (!empty($locationsData)) {
//                        $locationsData = array_pop($locationsData);
//                    }
//                }

//                $result = array();
//                if (!empty($locationsData)) {
//                    $map = array();
//                    foreach ($locationsData as $row) {
//                        if (is_null($toDistance) || ($toDistance < $row[4])) {
//                            $map[$row[0]] = $row;
//                        }
//                    }
//
//                    foreach ($data as $key => $row) {
//                        if (!empty($map[$row[1]])) {
//                            $result[] = array(
//                                'mark' => $row[4],
//                                'visited_at' => $row[3],
//                                'place' => $map[$row[1]][1],
//                                'country' => $map[$row[1]][2]
//                            );
//                        }
//                    }
//                }
//

                // Create mixed data struct to be returned back
                $response->setJsonContent(array('visits' => $result));
                $response->setContentType('application/json');
            }
        } catch (\Exception $e) {
            $response->setStatusCode(500);
        }

        return $response;
    }
);

$app->post('/users/new', function() use ($app, $userFields) {
    // Create new user - {}
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    $response = new Response();

    try {
        $data = validateUser($data, $userFields);

        $tarantool = $app->di->get('db_users');
        $data = $tarantool->insert('user', $data);

        // Warm up cache
        try {
            $cacheKey = '/users/' . $data[0][0] . '?';
            $cache = $app->di->get('cache');

            $struct = array_combine($userFields, $data[0]);
            @$cache->save($cacheKey, json_encode($struct), 20);
        } catch (Phalcon\Cache\Exception $e) {}

        $response->setContent('{}');
        return $response;
    } catch (\Exception $e) {
        $response->setStatusCode(400);
    }

    return $response;
});

$app->post('/locations/new', function() use ($app, $locationFields) {
    // Create new location - Expected {}
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    $response = new Response();

    try {
        $data = validateLocation($data, $locationFields);

        $tarantool = $app->di->get('db_locations');
        $data = $tarantool->insert('location', $data);

        // Warm up cache
        try {
            $cacheKey = '/locations/' . $data[0][0] . '?';
            $cache = $app->di->get('cache');

            $struct = array_combine($locationFields, $data[0]);
            @$cache->save($cacheKey, json_encode($struct), 20);
        } catch (Phalcon\Cache\Exception $e) {}


        $response->setContent('{}');
        return $response;
    } catch (\Exception $e) {
        $response->setStatusCode(400);
    }

    return $response;
});

$app->post('/visits/new', function() use ($app, $visitFields) {
    // Create new location - Expected {}
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    $response = new Response();

    try {
        $data = validateVisit($data, $visitFields);

        $tarantool = $app->di->get('db_visits');

        $data = $tarantool->insert('visit', $data);

        // Warm up cache
        try {
            $cacheKey = '/users/' . $data[0][0] . '?';
            $cache = $app->di->get('cache');

            $struct = array_combine($visitFields, $data[0]);
            @$cache->save($cacheKey, json_encode($struct), 20);
        } catch (Phalcon\Cache\Exception $e) {}

        $response->setContent('{}');
        return $response;
    } catch (\Exception $e) {
        $response->setStatusCode(400);
    }

    return $response;
});

$app->post('/visits/{id:[0-9]+}', function($id) use($app, $visitFields) {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    $response = new Response();
    $id = intval($id);

    try {
        $data['id'] = $id;

        // Select id first
        $tarantool = $app->di->get('db_visits');
        $record = $tarantool->select('visit', intval($id));

        if (empty($record)) {
            $response->setStatusCode(404);
            return $response;
        }

        $record = array_combine($visitFields, $record[0]);
        $data   = array_merge($record, $data);

        $data = validateVisit($data, $visitFields);
        $tarantool->replace('visit', array_values($data));

        try {
            $cacheKey = '/visits/' . $id . '?';

            $cache = $app->di->get('cache');
            $cache->delete($cacheKey);
        } catch (Phalcon\Cache\Exception $e) {}

    } catch (\Exception $e) {
        $response->setStatusCode(400);
    }

    return $response;
});

$app->post('/users/{id:[0-9]+}', function($id) use($app, $userFields) {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    $response = new Response();
    $id = intval($id);

    try {
        $data['id'] = $id;

        // Select id first
        $tarantool = $app->di->get('db_users');
        $record = $tarantool->select('user', $id);

        if (empty($record)) {
            $response->setStatusCode(404);
            return $response;
        }

        $record = array_combine($userFields, $record[0]);
        $data   = array_merge($record, $data);

        $data = validateUser($data, $userFields);
        $tarantool->replace('user', $data);

        try {
            $cacheKey = '/users/' . $id . '?';

            $cache = $app->di->get('cache');
            $cache->delete($cacheKey);
        } catch (Phalcon\Cache\Exception $e) {}

        $response->setContent('{}');

    } catch (\Exception $e) {
        $response->setStatusCode(400);
    }

    return $response;
});

$app->post('/locations/{id:[0-9]+}', function($id) use($app, $locationFields) {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    $response = new Response();
    $id = intval($id);

    try {
        $data['id'] = $id;

        // Select id first
        $tarantool = $app->di->get('db_locations');
        $record = $tarantool->select('location', intval($id));

        if (empty($record)) {
            $response->setStatusCode(404);
            return $response;
        }

        $record = array_combine($locationFields, $record[0]);
        $data   = array_merge($record, $data);

        $data = validateLocation($data, $locationFields);
        $tarantool->replace('location', array_values($data));

        try {
            $cacheKey = '/location/' . $id . '?';

            $cache = $app->di->get('cache');
            $cache->delete($cacheKey);
        } catch (Phalcon\Cache\Exception $e) {}

        $response->setContent('{}');
        return $response;

    } catch (\Exception $e) {
        $response->setStatusCode(400);
    }

    return $response;
});

$app->get('/locations/{id:[0-9]+}/avg', function($id) use ($app) {
        // fromDate - учитывать оценки только с visited_at > fromDate
        // toDate - учитывать оценки только до visited_at < toDate
        // fromAge - учитывать только путешественников, у которых возраст (считается от текущего timestamp) строго больше этого параметра
        // toAge - учитывать только путешественников, у которых возраст (считается от текущего timestamp) строго меньше этого параметра
        // gender - учитывать оценки только мужчин или женщин

        $request = new Request();
        $response = new Response();

        $fromDate    = $request->getQuery('fromDate');
        $toDate      = $request->getQuery('toDate');
        $fromAge     = $request->getQuery('fromAge');
        $toAge       = $request->getQuery('toAge');
        $gender      = $request->getQuery('gender');

        foreach (array('fromDate', 'toDate', 'fromAge', 'toAge') as $option) {
            if (isset($$option) && (!is_null($$option) && !is_numeric($$option))) {
                $response->setStatusCode(400);

                return $response;
            }
        }

        if (!is_null($gender) && !preg_match_all('/^(f|m)$/', $gender)) {
            $response->setStatusCode(400);

            return $response;
        }

        $skipFiltration = false;
        if (empty($fromAge) && empty($toAge) && empty($fromDate) && empty($toDate) && empty($gender)) {
            $skipFiltration = true;
        }

        /*
        В случае если места с переданным id нет - отдавать 404. Если по указанным параметрам не было посещений, то {"avg": 0}

        Небольшой пример проверки дат в этом запросе на python (fromAge - количество лет):

        from datetime import datetime
        from dateutil.relativedelta import relativedelta
        import calendar

        now = datetime.now() - relativedelta(years = fromAge)
        timestamp = calendar.timegm(now.timetuple())
        */

        $tarantool = $app->di->get('db_locations');
        $data = $tarantool->select('location', intval($id));

        if (!empty($data[0])) {
            $tarantool = $app->di->get('db_visits');
            $visitsData = $tarantool->select('visit', intval($data[0][0]), 'location');
            $cache = array();

            $result = array('mark' => 0, 'count' => 0);

            $currentTime = time();

            if (!empty($fromAge)) {
                $date = new \DateTime();
                $date->modify('- ' . $fromAge . ' years');

                $fromTimeStamp = $date->getTimestamp();
            }

            if (!empty($visitsData)) {
                if (!$skipFiltration) {
                    $tarantool = $app->di->get('db_users');

                    foreach ($visitsData as $visit) {
                        if (!empty($fromDate) && ($fromDate >= $visit[3])) {
                            continue;
                        }

                        if (!empty($toDate) && ($visit[3] >= $toDate)) {
                            continue;
                        }

                        if (empty($cache[$visit[2]])) {
                            $userData = $tarantool->select('user', $visit[2], 'primary');
                            $userData = array_pop($userData);
                        } else {
                            $userData = $cache[$visit[2]];
                        }

                        if (empty($userData)) {
                            continue;
                        }

                        $match = true;
                        if (!empty($gender) && ($gender != $userData[4])) {
                            $match = false;
                        }

                        if ($match && !empty($fromAge)) {
                            /** @var int $fromTimeStamp */
                            if ($fromTimeStamp <= $userData[5]) {
                                $match = false;
                            }
                        }

                        if ($match && !empty($toAge)) {
                            $date = new \DateTime();
                            $date->setTimestamp($userData[5]);
                            $date->modify('+ ' . $toAge . ' years');

                            if ($date->getTimestamp() >= $currentTime) {
                                $match = false;
                            }
                        }

                        if ($match) {
                            $result['mark'] += $visit[4];
                            $result['count']++;
                        }
                    }
                } else {
                    foreach ($visitsData as $visit) {
                        $result['mark']  += $visit[4];
                        $result['count']++;
                    }
                }
            }

            $avg = 0;

            if ($result['count']) {
                $avg = round($result['mark'] / $result['count'], 5);
            }

            $response->setJsonContent(array('avg' => $avg));
        } else {
            $response->setStatusCode(404);
        }

        return $response;
});

$app->notFound(function() use ($app) {
    $app->response->setStatusCode(404, "Not Found")->sendHeaders();
});

// Define the routes here
$app->handle();

/**
 * @param array $data
 * @param array $fields
 *
 * @return array
 * @throws InvalidDataException
 */
function validateUser($data, $fields) {
    $result = array();

    foreach ($fields as $index => $field) {
        if (empty($data[$field])) {
            throw new InvalidDataException('Empty field ' . $field);
        } elseif(isset($data[$field])) {
            $value = $data[$field];

            if (is_null($value)) {
                throw new InvalidDataException($field . " can't be null");
            }

            if ($field === 'email' && (false === strpos($value, '@'))) {
                throw new InvalidDataException('Invalid email');
            }

            if (($field === 'birth_date' || $field === 'id') && !is_numeric($value)) {
                throw new InvalidDataException('Invalid birth date');
            }

            if ($field === 'gender' && (($value != 'm') && ($value != 'f'))) {
                throw new InvalidDataException('Invalid gender');
            }

            $result[] = $value;
        }
    }

    return $result;
}

/**
 * @param array $data
 * @param array $fields
 *
 * @return array
 * @throws InvalidDataException
 */
function validateVisit($data, $fields) {
    $result = array();

    foreach ($fields as $field) {
        if (!isset($data[$field]) || is_null($data[$field])) {
            throw new InvalidDataException("Field {$field} can't be null");
        }

        $value = $data[$field];

        if (empty($value) || !is_numeric($value)) {
            throw new InvalidDataException("Incorrect field $field");
        }

        $result[] = $value;
    }

    return $result;
}

/**
 * @param array $data
 * @param array $fields
 *
 * @return array
 * @throws InvalidDataException
 */
function validateLocation($data, $fields) {
    $result = array();

    foreach ($fields as $field) {
        if (empty($data[$field])) {
            throw new InvalidDataException('Empty field ' . $field);
        } else {
            if (($field === 'distance' || $field === 'id') && !is_numeric($data[$field])) {
                throw new InvalidDataException('Invalid Distance');
            }

            $result[] = $data[$field];
        }
    }

    return $result;
}

class InvalidDataException extends \Exception {}