<?php
/**
 * Mail.ru Highload contest
 * v3 solution - based on Swoole.
 *
 * @author Evgeniy Bogdanov
 */

error_reporting(E_ALL ^ E_WARNING );

ini_set('memory_limit', '-1');

if (php_sapi_name() != 'cli') {
    die("CLI script only\n");
}

define('RESULT_OK', 1);
define('RESULT_FAILED', 2);
define('RESULT_NOT_FOUND', 3);

abstract class Storage {

    protected $isIndexReady = false;

    protected $fields = [];

    protected $data = [];

    protected $numberOfFields = 0;

    protected $timestamp;

    public function __construct() {
        $this->numberOfFields = count($this->fields);
    }

    /**
     * @param int $timestamp
     */
    public function setTimeStamp($timestamp) {
        $this->timestamp = $timestamp;
    }

    /**
     * Add new record into storage
     *
     * @param string $id
     * @param array $values
     *
     * @return bool
     */
    public function add($id, $values) : bool {
        // Check if exists / return false
        if (isset($this->data[$id])) {
            return false;
        }

        if (count($values) < ($this->numberOfFields)) {
            return false;
        }

        $this->data[$id] = $values;
        $this->isIndexReady = false;

        return true;
    }

    /**
     * Update fields
     *
     * @param int $id
     * @param array $values
     * @return int
     */
    public function update($id, $values) : int {
        if (!isset($this->data[$id])) {
            return RESULT_NOT_FOUND;
        }

        if (!$this->validate($values)) {
            return RESULT_FAILED;
        }

        $newData = [];
        foreach ($this->fields as $i => $field) {
            if (isset($values[$field])) {
                $newData[$i] = $values[$field];
            }
        }

        if (!empty($newData)) {
            $this->data[$id] = array_replace($this->data[$id], $newData);
        }
        $this->isIndexReady = false;

        return RESULT_OK;
    }

    /**
     * Get by ID
     *
     * @param $id
     * @return array|null
     */
    public function getById($id) {
        return is_numeric($id) && isset($this->data[$id])
            ? $this->data[$id]
            : null;
    }

    /**
     * Get JSON
     *
     * @param int $id
     * @return string|null
     */
    public function getJSON($id) : string {
        $data = $this->getById($id);
        if (is_null($data)) return '';

        $return = array_combine($this->getFields(), $data);
        return json_encode($return, true);
    }

    /**
     * @return array
     */
    public function getFields() : array {
        return $this->fields;
    }

    /**
     * Get size of stored records
     *
     * @return int
     */
    public function getSize() {
        return count($this->data);
    }

    /**
     * Validate values before inserting into database
     *
     * @param array $values
     * @return bool
     */
    abstract protected function validate($values) : bool;
}

class Users extends Storage {

    const FIELD_ID = 0;
    const FIELD_EMAIL = 1;
    const FIELD_FIRST_NAME = 2;
    const FIELD_LAST_NAME = 3;
    const FIELD_GENDER = 4;
    const FIELD_BIRTH_DATE = 5;

    const HIDDEN_FIELD_AGE = 6;

    protected $fields = ['id', 'email', 'first_name', 'last_name', 'gender', 'birth_date'];

    /**
     * @param string $id
     * @param array $values
     *
     * @return bool
     */
    public function add($id, $values): bool {
        if (!$this->validate($values)) {
            return false;
        }

        $values[Users::HIDDEN_FIELD_AGE] = floor(($this->timestamp - $values[Users::FIELD_BIRTH_DATE]) / 31536000);

        return parent::add($id, $values);
    }

    /**
     * @param int $id
     * @param array $values
     * @return int
     */
    public function update($id, $values): int {
        $return = parent::update($id, $values);

        if ($return !== RESULT_OK) {
            return $return;
        }

        if (!empty($values['birth_date'])) {
            $tmp = floor(($this->timestamp - $values['birth_date']) / 31536000);

            $this->data[$id][Users::HIDDEN_FIELD_AGE] = $tmp;
        }

        return RESULT_OK;
    }

    /**
     * @param int $id
     * @return string
     */
    public function getJSON($id) : string {
        $data = $this->getById($id);

        if (is_null($data)) return '';
        unset($data[Users::HIDDEN_FIELD_AGE]);

        $return = array_combine($this->getFields(), $data);
        return json_encode($return, true);
    }

    /**
     * @param array $values
     * @return bool
     */
    protected function validate($values) : bool {
        if (isset($values[Users::FIELD_BIRTH_DATE]) && !is_numeric($values[Users::FIELD_BIRTH_DATE])) {
            return false;
        }

        if (isset($values[Users::FIELD_ID]) && !is_numeric($values[Users::FIELD_ID])) {
            return false;
        }

        if (isset($values[Users::FIELD_GENDER]) && !preg_match('/^[fm]$/', $values[Users::FIELD_GENDER])) {
            return false;
        }

        return true;
    }
};

class Visits extends Storage {

    const FIELD_ID = 0;
    const FIELD_LOCATION = 1;
    const FIELD_USER = 2;
    const FIELD_VISITED_AT = 3;
    const FIELD_MARK = 4;

    /**
     * Location's visits index
     *
     * @var array
     */
    protected $_idxByLocationId = [];

    /**
     * User's visits index
     *
     * @var array
     */
    protected $_idxByUserId = [];

    /*
    id - уникальный внешний id посещения. Устанавливается тестирующей системой. 32-разрядное целое беззнакое число.
    location - id достопримечательности. 32-разрядное целое беззнаковое число.
    user - id путешественника. 32-разрядное целое беззнаковое число.
    visited_at - дата посещения, timestamp.
    mark - оценка посещения от 0 до 5 включительно. Целое число.
 */
    protected $fields = ['id', 'location', 'user', 'visited_at', 'mark'];

    /**
     * Add new record
     *
     * @param int $id
     * @param array $values
     *
     * @return bool
     */
    public function add($id, $values) : bool {
        if (!parent::add($id, $values)) {
            return false;
        }

        $this->addToIndexes($id, $values);

        return true;
    }

    /**
     * Update existing data
     *
     * @param int $id
     * @param array $values
     * @return int
     */
    public function update($id, $values): int {
        $record = $this->data[$id];
        $return = parent::update($id, $values);

        if ($return !== RESULT_OK) {
            return $return;
        }

        unset($this->_idxByLocationId[$record[Visits::FIELD_LOCATION]][$id]);
        unset($this->_idxByUserId[$record[Visits::FIELD_USER]][$id]);

        $this->addToIndexes($id, $record);

        return RESULT_OK;
    }

    /**
     * Add to indexes
     *
     * @param int $id
     * @param array $values
     */
    protected function addToIndexes($id, $values) {
        if (!isset($this->_idxByLocationId[$values[Visits::FIELD_LOCATION]])) {
            $this->_idxByLocationId[$values[Visits::FIELD_LOCATION]] = [];
        }
        if (empty($this->_idxByLocationId[$values[Visits::FIELD_LOCATION]][$id])) {
            $this->_idxByLocationId[$values[Visits::FIELD_LOCATION]][$id] = &$this->data[$id];
        }

        if (!isset($this->_idxByUserId[$values[Visits::FIELD_USER]])) {
            $this->_idxByUserId[$values[Visits::FIELD_USER]] = [];
        }

        if (empty($this->_idxByUserId[$values[Visits::FIELD_USER]][$id])) {
            $this->_idxByUserId[$values[Visits::FIELD_USER]][$id] = &$this->data[$id];
        }
    }

    /**
     * @param int $id
     * @return array
     */
    public function getUsersIndex($id) : array {
        $return = (isset($this->_idxByUserId[$id])) ? $this->_idxByUserId[$id] : [];

        if ($this->isIndexReady) return $return;

        usort($return, function ($a, $b) {
            if ($a[Visits::FIELD_VISITED_AT] > $b[Visits::FIELD_VISITED_AT]) {
                return 1;
            }

            return $a[Visits::FIELD_VISITED_AT] < $b[Visits::FIELD_VISITED_AT] ? -1 : 0;
        });

        $this->_idxByUserId[$id] = $return;

        return $return;
    }

    /**
     * Get values indexed by location
     *
     * @param int $locationId
     * @return array
     */
    public function getLocationsIndex($locationId) : array {
        return (isset($this->_idxByLocationId[$locationId])) ? $this->_idxByLocationId[$locationId] : [];
    }

    /**
     * Build Users's visits index
     */
    public function buildUsersIndex() {
        foreach ($this->_idxByUserId as $id => $index) {
            usort($index, function ($a, $b) {
                if ($a[Visits::FIELD_VISITED_AT] > $b[Visits::FIELD_VISITED_AT]) {
                    return 1;
                }

                return $a[Visits::FIELD_VISITED_AT] < $b[Visits::FIELD_VISITED_AT] ? -1 : 0;
            });

            $this->_idxByUserId[$id] = $index;
        }

        $this->isIndexReady = true;
    }

    /**
     * @param array $values
     * @return bool
     */
    protected function validate($values): bool {
        if (is_array($values)) {
            foreach ($values as $id => $value) {
                if (!is_numeric($value)) {
                    return false;
                }
            }
        }

        if (isset($values[Visits::FIELD_MARK]) && (
            ($values[Visits::FIELD_MARK] < 0) || ($values[Visits::FIELD_MARK] > 5))
        ) {
            return false;
        }

        return true;
    }
};

class Locations extends Storage {

    const FIELD_DISTANCE = 0;
    const FIELD_PLACE = 1;
    const FIELD_COUNTRY = 2;
    const FIELD_CITY = 3;
    const FIELD_ID = 4;

    /*
    id - уникальный внешний id достопримечательности. Устанавливается тестирующей системой. 32-разрядное целое беззнаковоее число.
    place - описание достопримечательности. Текстовое поле неограниченной длины.
    country - название страны расположения. unicode-строка длиной до 50 символов.
    city - название города расположения. unicode-строка длиной до 50 символов.
    distance - расстояние от города по прямой в километрах. 32-разрядное целое беззнаковое число.
    */
    protected $fields = ['distance', 'place', 'country', 'city', 'id'];

    /**
     * Validate values
     *
     * @param array $values
     * @return bool
     */
    protected function validate($values) : bool {
        return true;
    }

};


class Data  {

    /**
     * @var Users $users
     */
    protected static $users;

    /**
     * @var Visits $visits
     */
    protected static $visits;

    /**
     * @var Locations $locations
     */
    protected static $locations;

    /**
     * @param Users $users
     */
    public static function setUsers(Users &$users){
        self::$users = $users;
    }

    /**
     * @return Users
     */
    public static function getUsers() : Users {
        return self::$users;
    }

    /**
     * @return Visits
     */
    public static function getVisits() : Visits {
        return self::$visits;
    }

    /**
     * @param Visits $visits
     */
    public static function setVisits(Visits &$visits) {
        self::$visits = $visits;
    }

    /**
     * @return Locations
     */
    public static function getLocations() : Locations {
        return self::$locations;
    }

    /**
     * @param Locations $locations
     */
    public static function setLocations(Locations &$locations) {
        self::$locations = $locations;
    }
}


// Unzip Files
exec('mkdir /tmp/init && unzip /tmp/data/data.zip -d /tmp/init/');

// Read current time
$currentTime = time();

if (file_exists('/tmp/data/options.txt')) {
    $content = file_get_contents('/tmp/data/options.txt');
    if (preg_match('/^(\d+)\s/', $content, $matches)) {
        echo 'Loaded timestamp: ' . $matches[1] . PHP_EOL;
        $currentTime = $matches[1];
    }
}

$usersStorage     = new Users();
$usersStorage->setTimeStamp($currentTime);

$visitsStorage    = new Visits();
$locationsStorage = new Locations();

$Data = new Data();

echo 'Import data', PHP_EOL;

$masks = ['users', 'visits', 'locations'];
// Read Data
foreach ($masks as $mask) {
    $files = glob('/tmp/init/' . $mask . '*.json');

    foreach ($files as $fileName) {
        $data = file_get_contents($fileName);
        $json = json_decode($data, true);

        if (false !== strpos($fileName, 'user')) {
            array_walk($json['users'], function($row) use ($usersStorage) {
                $id = $row['id'];

                // Calculate age
                $usersStorage->add($id, [
                    $id,
                    $row['email'],
                    $row['first_name'],
                    $row['last_name'],
                    $row['gender'],
                    $row['birth_date']
                ]);
            });

        } elseif (false !== strpos($fileName, 'visit')) {
            array_walk($json['visits'], function($row) use ($visitsStorage) {
                $visitsStorage->add($row['id'], [
                    $row['id'],
                    $row['location'],
                    $row['user'],
                    $row['visited_at'],
                    $row['mark']
                ]);
            });
        } elseif (false !== strpos($fileName, 'location')) {
            array_walk($json['locations'], function($row) use ($locationsStorage) {
                $locationsStorage->add($row['id'], [
                    $row['distance'],
                    $row['place'],
                    $row['country'],
                    $row['city'],
                    $row['id']
                ]);
            });
        }
    }
}

unset($files);
unset($data);
unset($json);

// Rebuild User's index with checking visited_at
$visitsStorage->buildUsersIndex();

$Data::setUsers($usersStorage);
$Data::setVisits($visitsStorage);
$Data::setLocations($locationsStorage);

echo 'Users records ',$usersStorage->getSize(),PHP_EOL;
echo 'Visits records ',$visitsStorage->getSize(),PHP_EOL;
echo 'Locations records ',$locationsStorage->getSize(),PHP_EOL,PHP_EOL;

echo 'Started web-server...' , PHP_EOL;

// Start Web Server
$http = new Swoole\Http\Server('0.0.0.0', 80, SWOOLE_BASE);
$http->set([
    'log_file' => '/dev/null',
    'max_request' => 20,   //reload worker by run xx times
    'worker_num'  => 100,
    'dispatch_mode' => 3,   // who come first who is
    'reactor_num'   => 1,   // depend cpu how much cpu you have
    'backlog'       => 512, // accept queue
    'open_cpu_affinity' => 1, // get cpu more time
    'open_tcp_nodelay' => 1, // for small packet to open
    'max_conn' => 20000,
    'open_tcp_keepalive' => 0,
    'daemonize' => 0,
]);

$http->on('request', function ($request, $response) use(&$Data) {
    $query = parse_url($request->server['request_uri']);
    $jsonOutput = '';

    // Get request options
    $uri = explode('/', rtrim($query['path'], '/'));

    // Process POST
    if ($request->server['request_method'] === 'POST') {
        $POST = $request->rawContent();

        // Decode input JSON
        $jsonInput = json_decode($POST, true);

        if (!empty($jsonInput)) {
            foreach ($jsonInput as $field => $value) {
                if (is_null($value)) {
                    $response->status(400);
                    $response->end();
                    return;
                }
            }
        } else {
            $response->status(400);
            $response->end();
            return;
        }

        // OK, we've input. Now we need to understand what we do - add new,
        // or update old

        if ($uri[2] === 'new') {
            // We're adding new entity

            $result = null;
            if ($uri[1] === 'users') {
                $result = $Data::getUsers()->add($jsonInput['id'], [
                    $jsonInput['id'],
                    $jsonInput['email'],
                    $jsonInput['first_name'],
                    $jsonInput['last_name'],
                    $jsonInput['gender'],
                    $jsonInput['birth_date']
                ]);
            } elseif ($uri[1] === 'visits') {
                $result = $Data::getVisits()->add($jsonInput['id'], [
                    $jsonInput['id'],
                    $jsonInput['location'],
                    $jsonInput['user'],
                    $jsonInput['visited_at'],
                    $jsonInput['mark']
                ]);
            } elseif ($uri[1] === 'locations') {
                $result = $Data::getLocations()->add($jsonInput['id'], [
                    $jsonInput['distance'],
                    $jsonInput['place'],
                    $jsonInput['country'],
                    $jsonInput['city'],
                    $jsonInput['id']
                ]);
            }

            if ($result === true) {
                $response->header('Content-Type', 'application/json');
                $response->header('Content-Length', 2);
                $response->end('{}');
            } elseif ($result === false) {
                $response->status(400);
                $response->end();
                return;
            }

        } elseif (is_numeric($uri[2])) {
            // We're updating entity
            $result = null;
            $id = $uri[2] + 0;

            if ($uri[1] === 'users') {
                $result = $Data::getUsers()->update($id, $jsonInput);
            } elseif ($uri[1] === 'visits') {
                $result = $Data::getVisits()->update($id, $jsonInput);
            } elseif ($uri[1] === 'locations') {
                $result = $Data::getLocations()->update($id, $jsonInput);
            }

            if ($result === RESULT_OK) {
                $response->header('Content-Type', 'application/json');
                $response->header('Content-Length', 2);
                $response->end('{}');
            } elseif ($result === RESULT_FAILED) {
                $response->status(400);
                $response->end();
                return;
            } elseif ($result === RESULT_NOT_FOUND) {
                $response->status(404);
                $response->end();
                return;
            }
        }

        if (!$jsonOutput) {
            $response->status(404);
            $response->end();
            return;
        }
    } else {
        // Process GET commands
        $argCount = count($uri);
        // Process simple queries
        if ($argCount == 3) {

            $id = $uri[2];
            if ($uri[1] == 'users') {
                $jsonOutput = $Data::getUsers()->getJSON($id);
            } elseif($uri[1] == 'visits') {
                $jsonOutput = $Data::getVisits()->getJSON($id);
            } elseif($uri[1] == 'locations') {
                $jsonOutput = $Data::getLocations()->getJSON($id);
            }
        } elseif($argCount == 4) {
            // Process complex queries
            // /users<id>/visits
            if ($uri[1] == 'users' && $uri[3] == 'visits') {
                $id = $uri[2];

                $params = [];
                if (isset($request->server['query_string'])) {
                    parse_str($request->server['query_string'], $params);
                }

                // $fromDate, $toDate, $country, $toDistance
                // Validate request
                foreach (['fromDate', 'toDate', 'toDistance'] as $option) {
                    if (isset($params[$option]) && (is_null($params[$option]) || !is_numeric($params[$option]))) {
                        $response->status(400);
                        $response->end();
                        return;
                    }
                }

                $user = $Data::getUsers()->getById($id);

                if (!empty($user)) {
                    $index = $Data::getVisits()->getUsersIndex($id);

                    $return = [];
                    foreach ($index as $row) {
                        if (isset($params['fromDate']) && ($row[Visits::FIELD_VISITED_AT] <= $params['fromDate'])) {
                            continue;
                        }

                        if (isset($params['toDate']) && ($params['toDate'] <= $row[Visits::FIELD_VISITED_AT])) {
                            continue;
                        }

                        if (!empty($row)) {
                            $location = $Data::getLocations()->getById($row[Visits::FIELD_LOCATION]);

                            if (empty($location)) {
                                continue;
                            }

                            // Check for distance && country
                            if (isset($params['toDistance']) && ($params['toDistance'] <= $location[Locations::FIELD_DISTANCE])) {
                                continue;
                            }

                            if (!isset($params['country']) || (0 === strcmp($params['country'], $location[Locations::FIELD_COUNTRY]))) {
                                $return[] = [
                                    'mark' => $row[Visits::FIELD_MARK],
                                    'visited_at' => $row[Visits::FIELD_VISITED_AT],
                                    'place'   => $location[Locations::FIELD_PLACE]
                                ];
                            }
                        }
                    }

                    $jsonOutput = json_encode(['visits' => $return]);
                }

                // fromDate, toDate,
            }
            elseif ($uri[1] == 'locations' && $uri[3] == 'avg') {
                // /locations/<id>/avg

                $id = intval($uri[2]);

                /*
                 fromDate - учитывать оценки только с visited_at > fromDate
                 toDate - учитывать оценки только до visited_at < toDate
                 fromAge - учитывать только путешественников, у которых возраст (считается от текущего timestamp) строго больше этого параметра
                 toAge - учитывать только путешественников, у которых возраст(считается от текущего timestamp) строго меньше этого параметра
                 gender - учитывать оценки только мужчин или женщин
                 */
                $params = [];
                if (isset($request->server['query_string'])) {
                    parse_str($request->server['query_string'], $params);
                }

                foreach (['fromDate', 'toDate', 'fromAge', 'toAge'] as $option) {
                    if (isset($params[$option]) && (is_null($params[$option]) || !is_numeric($params[$option]))) {
                        $response->status(400);
                        $response->end();
                        return;
                    }
                }

                if (isset($params['gender']) && !preg_match_all('/^[fm]$/', $params['gender'])) {
                    $response->status(400);
                    $response->end();
                    return;
                }

                // OK, now let's calculate average value
                $location = $Data::getLocations()->getById($id);

                if (empty($location)) {
                    $response->status(404);
                    $response->end();
                    return;
                }

                $lookupUserData = isset($params['fromAge'])
                    || isset($params['toAge'])
                    || isset($params['gender']);

                // Get list of visits in this location
                $visits = $Data::getVisits()->getLocationsIndex($id);

                $averageValue = 0;
                $sum   = 0;
                $count = 0;

                foreach ($visits as &$visit) {
                    // Check condition visit [fromDate/toDate]
                    if (isset($params['fromDate']) && ($params['fromDate'] >= $visit[Visits::FIELD_VISITED_AT])) {
                        continue;
                    }

                    if (isset($params['toDate']) && ($params['toDate'] <= $visit[Visits::FIELD_VISITED_AT])) {
                        continue;
                    }

                    // Get user's data for matching conditions
                    // fromAge/toAge/gender
                    if ($lookupUserData) {
                        $userData = Data::getUsers()->getById($visit[Visits::FIELD_USER]);

                        if (isset($params['fromAge']) && $userData[Users::HIDDEN_FIELD_AGE] < $params['fromAge']) {
                            continue;
                        } elseif (isset($params['toAge']) && $userData[Users::HIDDEN_FIELD_AGE] >= $params['toAge']) {
                            continue;
                        } elseif (isset($params['gender']) && $userData[Users::FIELD_GENDER] != $params['gender']) {
                            continue;
                        }
                    }

                    $sum += $visit[Visits::FIELD_MARK];
                    $count++;
                }

                if ($count) {
                    $averageValue = round($sum / $count, 5);
                }

                $jsonOutput = json_encode(['avg' => $averageValue]);
            }
        }

        if ($jsonOutput !== '') {
            $response->header('Content-Type', 'application/json');
            $response->end($jsonOutput);
            return;
        }

        $response->status(404);
        $response->end();
        return;
    }
});

$http->start();