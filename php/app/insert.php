<?php

if (php_sapi_name() != 'cli') {
    die("CLI script only\n");
}

eio_set_max_parallel(300);

$masks = array('users', 'visits', 'locations');

foreach ($masks as $mask) {
    $files = glob('/var/data/' . $mask . '*.json');

    foreach ($files as $fileName) {
        echo $fileName,PHP_EOL;
        $functionName = false;
        // Open the file for reading only
        eio_open($fileName, EIO_O_RDONLY, NULL, EIO_PRI_DEFAULT, "fileOpenCallback", $fileName);
    }
}

//eio_event_loop();

/**
 * Called when eio_open() is done
 *
 * @param string $fileName
 * @param int $fileDescriptor
 */
function fileOpenCallback($fileName, $fileDescriptor) {
    // $result should contain the file descriptor
    if ($fileDescriptor > 0) {
        $functionName = false;

        if (false !== strpos($fileName, 'user')) {
            $functionName = 'importUsers';
        } elseif (false !== strpos($fileName, 'visit')) {
            $functionName = 'importVisits';
        } elseif (false !== strpos($fileName, 'location')) {
            $functionName = 'importLocations';
        }

        if ($functionName) {
            $fileSize = filesize($fileName);
            eio_read($fileDescriptor, $fileSize, 0, EIO_PRI_DEFAULT, $functionName, $fileDescriptor);
        } else {
            eio_close($fileDescriptor);
        }

        eio_event_loop();
    }
}

/**
 * @param int $fileDescriptor
 * @param string $result
 */
function importUsers($fileDescriptor, $result) {
    $json = json_decode($result, true);
    $tarantool = new Tarantool();

    array_walk($json['users'], function($row) use ($tarantool) {
        $insert = array(
            $row['id'],
            $row['email'],
            $row['first_name'],
            $row['last_name'],
            $row['gender'],
            $row['birth_date']
        );

        $tarantool->insert('user', $insert);
    });

    // Close file
    eio_close($fileDescriptor);
    eio_event_loop();
}

/**
 * @param int $fileDescriptor
 * @param string $result
 */
function importVisits($fileDescriptor, $result) {
    $json = json_decode($result, true);

    $tarantool = new Tarantool('localhost', 3302);

    foreach ($json['visits'] as $row) {
        $insert = array(
            $row['id'],
            $row['location'],
            $row['user'],
            $row['visited_at'],
            $row['mark']
        );

        $tarantool->insert('visit', $insert);
    }

    // Close file
    eio_close($fileDescriptor);
    eio_event_loop();
}

/**
 * @param int $fileDescriptor
 * @param string $result
 */
function importLocations($fileDescriptor, $result) {
    $json = json_decode($result, true);

    $tarantool = new Tarantool('localhost', 3303);

    foreach ($json['locations'] as $row) {
        $insert = array(
            $row['id'],
            $row['place'],
            $row['country'],
            $row['city'],
            $row['distance']
        );

        $tarantool->insert('location', $insert);
    }

    eio_close($fileDescriptor);
    eio_event_loop();
}