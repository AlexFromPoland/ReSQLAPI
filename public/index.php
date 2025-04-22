<?php

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Factory\AppFactory;


require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
$app = AppFactory::create();



function Response($response, $responseData)
{
    $response->getBody()->write(json_encode($responseData));
    return $response->withHeader('Content-Type', 'application/json');
}


function getDatabaseConnection($ServerHost = null, $database = null, $Username = null, $Password = null)
{
    $ServerHost = $ServerHost ?? $_ENV["MAIN_DB_DOMAIN"];
    $database   = $database   ?? $_ENV["MAIN_DB_NAME"];
    $Username   = $Username   ?? $_ENV["MAIN_DB_USER"];
    $Password   = $Password   ?? $_ENV["MAIN_DB_PASS"];

    try {
        $conn = new PDO("mysql:host=$ServerHost;dbname=$database", $Username, $Password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $conn;
    } catch (PDOException $e) {
        return ['error' => true, 'message' => $e->getMessage()];
    }
}


function detectSQLType(string $query): string
{
    $clean = trim($query);

    // Blokuj groźne komendy
    if (preg_match('/\b(DROP|TRUNCATE|ALTER|GRANT|REVOKE|--)\b/i', $clean)) {
        return 'forbidden';
    }

    // Rozpoznaj typ zapytania
    if (preg_match('/^\s*SELECT\b/i', $clean)) return 'select';
    if (preg_match('/^\s*INSERT\b/i', $clean)) return 'insert';
    if (preg_match('/^\s*UPDATE\b/i', $clean)) return 'update';
    if (preg_match('/^\s*DELETE\b/i', $clean)) return 'delete';

    return 'unknown';
}


//ENDPOINTY API
$app->get('/', function (Request $request, Response $response) {

    $responseData = ['greetings' => "Welcome To Main RAPI site!", "avalaible endpoints" => ['GetAPIKey', 'AddDB', 'SQLCommand']];

    return Response($response, $responseData);
});

$app->get('/GetAPIKey', function (Request $request, Response $response) {

    $responseData = [null];

    $headers = $request->getHeaders();
    $authHeader = $headers['Authorization'][0] ?? '';

    $token = str_replace('Bearer ', '', $authHeader);


    //dodać .env i odcytywać dane z .env
    if ($token !== $_ENV['TOKEN']) {
        return Response($response, ['status' => 'error', 'message' => 'Unauthorized']);
    }



    $params = $request->getQueryParams();;

    $USERNAME = $params['user'] ?? null;


    if (!$USERNAME) {
        $responseData = ['RAPI' => "message", 'API_ENDPOINT' => "GetAPIKey", "Usage" => " GetAPIKey?user=NAME", "Descrypion" => "NAME is your USERNAME"];


        return Response($response, $responseData);
    }


    try {

        $conn = getDatabaseConnection();

        if (is_array($conn) && $conn['error']) {
            $responseData = [
                'status' => 'error',
                'reason' => $conn['message']
            ];
            return Response($response, $responseData);
        }

        $apiKey = bin2hex(random_bytes(16));

        $stmt = $conn->prepare("INSERT INTO apikey (APIKEY,USERNAME)VALUES( :api_key,:username)");
        $stmt->bindParam(':username', $USERNAME);
        $stmt->bindParam(':api_key', $apiKey);
        $stmt->execute();

        $responseData = [
            'status' => 'success',
            'apiKey' => $apiKey,
            'user' => $USERNAME
        ];
    } catch (PDOException $e) {

        $responseData = [
            'status' => 'error',
            'reason' => $e->getMessage()
        ];
    }
    $conn = null;
    return Response($response, $responseData);
});


$app->get('/AddDB', function (Request $request, Response $response) {



    $responseData = [null];
    $params = $request->getQueryParams();


    $headers = $request->getHeaders();
    $authHeader = $headers['Authorization'][0] ?? '';

    $token = str_replace('Bearer ', '', $authHeader);


    $conn = getDatabaseConnection();

    // Sprawdź czy połączenie się nie nie udało
    if (is_array($conn) && $conn['error']) {
        $responseData = [
            'status' => 'error',
            'reason' => $conn['message']
        ];
        return Response($response, $responseData);
    }
    $stmt = $conn->prepare("SELECT ID FROM apikey WHERE APIKEY=:api_key");
    $stmt->bindParam(':api_key', $token);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        return Response($response, ['status' => 'error', 'message' => 'Unauthorized']);
    } else {

        $USERNAME = $params['user'] ?? null;
        $DB_NAME = $params['db'] ?? null;
        $DB_PASSWORD = $params['pass'] ?? null;
        $DOMAIN = $params['host'] ?? null;

        if (!$DB_NAME || !$DB_PASSWORD || !$DOMAIN || !$USERNAME) {
            $responseData = [
                'RAPI' => "message",
                'API_ENDPOINT' => "AddDB",
                "Usage" => " AddDB?db=DB_NAME&pass=DB_PASS&host=SERV_HOST&user=USER",
                "Descrypion" =>
                [
                    "DB_NAME its a name of your database you want to add",
                    "DB_PASS is password for your database you want to add if you dont have password give as name \"BLANK_\"",
                    "SERV_HOST is domain where database is located",
                    "USERNAME is user connected to this database"
                ]
            ];



            return Response($response, $responseData);
        }

        try {
            $conn = getDatabaseConnection();

            // Sprawdź czy połączenie się nie nie udało
            if (is_array($conn) && $conn['error']) {
                $responseData = [
                    'status' => 'error',
                    'reason' => $conn['message']
                ];
                return Response($response, $responseData);
            }

            if ($DB_PASSWORD == "BLANK_") {
                $DB_PASSWORD = '';
            }

            $insert = $conn->prepare("INSERT INTO proxy_sql (DB_NAME,DB_PASSWORD,DOMAIN,USERNAME) VALUES (:DBNAME,:DBPASS,:DOMAIN,:USER)");
            $insert->bindParam(':DBNAME', $DB_NAME);
            $insert->bindParam(':DBPASS', $DB_PASSWORD);
            $insert->bindParam(':DOMAIN', $DOMAIN);
            $insert->bindParam(':USER', $USERNAME);

            $insert->execute();

            $responseData = ['status' => 'success', 'message' => 'added DB[' . $DB_NAME . '] to PROXY you can now acces your DB from anywhere'];
        } catch (PDOException $e) {
            $responseData = ['SQL ERROR' => 'error:' . $e];
        }

        $conn = null;


        return Response($response, $responseData);
    }
});



$app->get('/SQLCommand', function (Request $request, Response $response) {

    $responseData = [null];
    $params = $request->getQueryParams();
    $headers = $request->getHeaders();
    $authHeader = $headers['Authorization'][0] ?? '';

    $token = str_replace('Bearer ', '', $authHeader);


    $conn = getDatabaseConnection();

    // Sprawdź czy połączenie się nie nie udało
    if (is_array($conn) && $conn['error']) {
        $responseData = [
            'status' => 'error',
            'reason' => $conn['message']
        ];
        return Response($response, $responseData);
    }
    $stmt = $conn->prepare("SELECT ID FROM apikey WHERE APIKEY=:api_key");
    $stmt->bindParam(':api_key', $token);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        return Response($response, ['status' => 'error', 'message' => 'Unauthorized']);
    } else {


        $DB_NAME = $params['db'] ?? null;
        $PROMPT = $params['sql'] ?? NULL;

        if (!$DB_NAME || !$PROMPT) {
            $responseData = [
                'RAPI' => "message",
                'API_ENDPOINT' => "SQLCommand",
                "Usage" => " SQLCommand?db=DB_NAME&sql=SQL_COMMAND",
                "Descrypion" =>
                [

                    "DB_NAME its a name of your database",
                    "SQL_COMMAND is your sql prompt to this database"
                ]

            ];


            return Response($response, $responseData);
        }
        try {
            $conn = getDatabaseConnection();

            // Sprawdź czy połączenie się nie nie udało
            if (is_array($conn) && $conn['error']) {
                $responseData = [
                    'status' => 'error',
                    'reason' => $conn['message']
                ];
                return Response($response, $responseData);
            }

            $check_DB = $conn->prepare("SELECT DOMAIN,USERNAME,DB_NAME,DB_PASSWORD FROM proxy_sql WHERE DB_NAME=:db");
            $check_DB->bindParam(':db', $DB_NAME);
            $check_DB->execute();
            $chDB_result = $check_DB->fetch(PDO::FETCH_ASSOC);
            if ($chDB_result) {




                $proxy_sql = getDatabaseConnection($chDB_result["DOMAIN"], $chDB_result["DB_NAME"], $chDB_result["USERNAME"], $chDB_result["DB_PASSWORD"]);
                if (is_array($proxy_sql) && $proxy_sql['error']) {
                    $responseData = [
                        'status' => 'error',
                        'reason' => $proxy_sql['message']
                    ];
                    return Response($response, $responseData);
                }

                $SQL_TYPE = detectSQLType($PROMPT);


                switch ($SQL_TYPE) {
                    case "select":
                        $stmt = $proxy_sql->query($PROMPT);
                        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        $responseData = ['status' => 'success', 'result' => $data];
                        break;
                    case "insert":
                    case "update":
                    case "delete":
                        $rows = $proxy_sql->exec($PROMPT);
                        $responseData = ['status' => 'success', 'rows_affected' => $rows];

                        break;
                    case "unknown":
                    case "forbidden":
                        return Response($response, ["message", "Unsuported command"]);
                        break;
                }
            } else {
                $responseData = ['status' => 'error', 'message' => 'could not find database named ' . $DB_NAME];
            }
        } catch (PDOException $e) {
            $responseData = ['SQL ERROR' => 'error:' . $e];
        }

        $conn = null;


        return Response($response, $responseData);
    }
});


$app->run();
